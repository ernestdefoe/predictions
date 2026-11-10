<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Notification\PredictionCalledBlueprint;
use ErnestDefoe\Predictions\Notification\PredictionLockedBlueprint;
use ErnestDefoe\Predictions\Notification\PredictionMissedBlueprint;
use Flarum\Notification\NotificationSyncer;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Writes every pick's result for a settled prediction, rebuilds the season
 * scores of everyone who picked it, then tells them how they did.
 *
 * 🚨 Idempotent and race-safe: it runs under the prediction's row lock and
 * only while scored_at is empty. The settle request queues it; the scheduler
 * (predictions:tick) picks up any that a lost queue worker never ran. Two of
 * them at once: the second waits for the lock, sees scored_at, and stops.
 */
class Scorer
{
    public function __construct(
        private readonly Scores $scores,
        private readonly NotificationSyncer $notifications,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function score(int $predictionId): void
    {
        $result = Prediction::query()->getConnection()->transaction(function () use ($predictionId) {
            $prediction = Prediction::query()->whereKey($predictionId)->lockForUpdate()->first();
            if (! $prediction || ! $prediction->isSettled() || $prediction->scored_at !== null) {
                return null;
            }

            // 🚨 Whoever settles it can't score from it: otherwise a member could open a
            // yes/no, pick, settle it their way and farm points all day.
            $settler = $prediction->settled_by_id;
            $all = Pick::query()->where('prediction_id', $prediction->id)->get(['id', 'user_id', 'option_id', 'value']);
            $picks = $all->filter(fn (Pick $pick) => (int) $pick->user_id !== $settler)->values();
            $own = $all->first(fn (Pick $pick) => (int) $pick->user_id === $settler);
            if ($own) {
                Pick::query()->whereKey($own->id)->update(['hit' => null, 'points' => null]);
            }

            // 🚨 Too few pickers and it doesn't count: otherwise two accounts farm points (A opens,
            // B picks, A settles). Who called it is still shown; the points are not given.
            $counted = $prediction->voided_at === null && $picks->count() >= Prediction::MIN_PICKERS;

            if ($prediction->voided_at !== null) {
                $results = [];
                Pick::query()->where('prediction_id', $prediction->id)->update(['hit' => null, 'points' => null]);
            } else {
                $results = $prediction->type === Prediction::NUMBER
                    ? Scoring::number($picks->pluck('value', 'id')->map(fn ($v) => $v === null ? null : (float) $v)->all(), (float) $prediction->answer_value, $prediction->tolerance)
                    : Scoring::choice($picks->pluck('option_id', 'id')->map(fn ($v) => $v === null ? null : (int) $v)->all(), (int) $prediction->correct_option_id, (bool) $this->settings->get(Settings::BOLD_BONUS));

                // One UPDATE per distinct result, not one per pick: at most 21 statements however many picked.
                $groups = [];
                foreach ($results as $id => $r) {
                    $groups[($r['hit'] ? 1 : 0).':'.($counted ? $r['points'] : '')][] = $id;
                }
                foreach ($groups as $key => $ids) {
                    [$hit, $points] = explode(':', $key);
                    foreach (array_chunk($ids, 500) as $chunk) {
                        Pick::query()->whereIn('id', $chunk)->update(['hit' => (bool) $hit, 'points' => $points === '' ? null : (int) $points]);
                    }
                }
            }

            if ($prediction->type === Prediction::NUMBER && $prediction->voided_at === null) {
                $prediction->stats = array_merge($prediction->stats ?? [], ['closest' => $this->closest($picks->all(), $results, (float) $prediction->answer_value)]);
            }

            $prediction->scored_at = Carbon::now();
            $prediction->counted = $counted;
            $prediction->save();

            $userIds = $picks->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
            if ($prediction->season_id) {
                $this->scores->rebuild($prediction->season_id, $own ? [...$userIds, (int) $own->user_id] : $userIds);
            }

            $hits = [];
            foreach ($picks as $pick) {
                if ($results[$pick->id]['hit'] ?? false) {
                    $hits[] = (int) $pick->user_id;
                }
            }

            return [$prediction, $hits, array_values(array_diff($userIds, $hits))];
        });

        if ($result) {
            $this->notify(...$result);
        }
    }

    /**
     * "You called it!" to everyone who did, "It's settled" to everyone who
     * didn't. sync() also takes back the alert from anyone a correction moved
     * to the other list, and a void takes back both.
     *
     * @param list<int> $hits
     * @param list<int> $misses
     */
    private function notify(Prediction $prediction, array $hits, array $misses): void
    {
        // The creator's "time to settle" alert has done its job.
        $this->notifications->sync(new PredictionLockedBlueprint($prediction), []);

        $void = $prediction->voided_at !== null;

        foreach ([[new PredictionCalledBlueprint($prediction), $void ? [] : $hits], [new PredictionMissedBlueprint($prediction), $void ? [] : $misses]] as [$blueprint, $ids]) {
            $users = [];
            foreach (array_chunk($ids, 500) as $chunk) {
                array_push($users, ...User::query()->whereIn('id', $chunk)->get()->all());
            }
            $this->notifications->sync($blueprint, $users);
        }
    }

    /**
     * The three closest guesses, kept with the result so a card can say who
     * got nearest without loading every pick.
     *
     * @param list<Pick> $picks
     * @param array<int, array{hit: bool, points: int}> $results
     * @return list<array{userId: int, value: float, points: int}>
     */
    private function closest(array $picks, array $results, float $answer): array
    {
        $rows = [];
        foreach ($picks as $pick) {
            if ($pick->value !== null) {
                $rows[] = ['userId' => (int) $pick->user_id, 'value' => (float) $pick->value, 'points' => $results[$pick->id]['points'] ?? 0];
            }
        }
        usort($rows, fn ($a, $b) => abs($a['value'] - $answer) <=> abs($b['value'] - $answer) ?: $a['userId'] <=> $b['userId']);

        return array_slice($rows, 0, 3);
    }
}
