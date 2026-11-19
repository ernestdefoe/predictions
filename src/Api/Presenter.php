<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Option;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * A prediction's attributes as the browser gets them (PredictionResource reads
 * them through Cards; creator and discussion are the resource's relationships).
 *
 * 🚨 A fixed number of queries for any number of cards: options are eager
 * loaded, and the viewer's own picks, the "closest guesses" faces and the ended
 * seasons are one query each. Nothing here loads per prediction.
 *
 * Other people's picks (option counts, guess stats) are left out while the
 * creator keeps them hidden and the prediction is still open.
 */
class Presenter
{
    public function __construct(
        private readonly SlugManager $slugs,
        private readonly UrlGenerator $url,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /**
     * @param Collection<int, Prediction> $predictions
     * @return list<array<string, mixed>>
     */
    public function many(Collection $predictions, User $actor): array
    {
        if ($predictions->isEmpty()) {
            return [];
        }

        (new EloquentCollection($predictions->all()))->loadMissing('options');

        $mine = $actor->isGuest() ? collect() : Pick::query()
            ->where('user_id', $actor->id)
            ->whereIn('prediction_id', $predictions->pluck('id'))
            ->get()->keyBy('prediction_id');

        $closestIds = $predictions->flatMap(fn (Prediction $p) => array_column($p->stats['closest'] ?? [], 'userId'))->unique()->values();
        $closestUsers = $closestIds->isEmpty() ? collect() : User::query()->whereVisibleTo($actor)->whereIn('id', $closestIds)->get()->keyBy('id');

        // Which seasons are over, once for the page (a settled prediction in one can't be re-settled).
        $seasonIds = $predictions->pluck('season_id')->filter()->unique()->values();
        $ended = $seasonIds->isEmpty() ? [] : Season::query()->whereIn('id', $seasonIds)->whereNotNull('ended_at')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $minPickers = (int) $this->settings->get(Settings::MIN_PICKERS);

        return $predictions->map(fn (Prediction $p) => $this->one($p, $actor, $mine->get($p->id), $closestUsers, in_array($p->season_id, $ended, true)) + ['minPickers' => $minPickers])->values()->all();
    }

    /**
     * @param Collection<int, User> $closestUsers
     * @return array<string, mixed>
     */
    private function one(Prediction $p, User $actor, ?Pick $mine, Collection $closestUsers, bool $endedSeason): array
    {
        $result = $p->isSettled() && $p->voided_at === null;

        return [
            'id' => $p->id,
            'status' => $p->status(),
            'picksVisible' => $p->picksVisible(),
            'picksCount' => $p->picks_count,
            'options' => $this->options($p, $result),
            'stats' => $this->stats($p, $closestUsers),
            'answer' => $result ? $p->answer_value : null,
            'correctOptionId' => $result ? $p->correct_option_id : null,
            'settledAt' => $p->settled_at?->toIso8601String(),
            'scored' => $p->scored_at !== null,
            // Null until scored; false when too few picked for it to count on the leaderboard.
            'counted' => $p->scored_at !== null ? (bool) $p->counted : null,
            'myPick' => $mine?->toRow(),
            'url' => $this->url->to('forum')->route('predictions.show', ['id' => $p->id]),
            'createdAt' => $p->created_at->toIso8601String(),
            'can' => $this->abilities($p, $actor, $p->picks_count - ($mine ? 1 : 0), $endedSeason),
        ];
    }

    /**
     * Other people's picks (option counts) are left out while the creator keeps them hidden.
     *
     * @return list<array{id: int, label: string, count: int|null, isCorrect: bool|null}>
     */
    private function options(Prediction $p, bool $result): array
    {
        if ($p->type === Prediction::NUMBER) {
            return [];
        }
        $visible = $p->picksVisible();

        return $p->options->map(fn (Option $o) => [
            'id' => $o->id,
            'label' => $o->label,
            'count' => $visible ? $o->picks_count : null,
            'isCorrect' => $result ? $o->id === $p->correct_option_id : null,
        ])->values()->all();
    }

    /**
     * A number's guess stats, with the closest guessers' faces; hidden like option counts.
     *
     * @param Collection<int, User> $closestUsers
     * @return array<string, mixed>|null
     */
    private function stats(Prediction $p, Collection $closestUsers): ?array
    {
        if ($p->type !== Prediction::NUMBER || ! $p->picksVisible() || ! $p->stats) {
            return null;
        }

        $stats = $p->stats;
        $stats['closest'] = array_values(array_filter(array_map(function (array $row) use ($closestUsers) {
            $user = $closestUsers->get($row['userId']);

            return $user ? ['user' => $this->user($user), 'value' => $row['value'], 'points' => $row['points']] : null;
        }, $p->stats['closest'] ?? [])));

        return $stats;
    }

    /** @return array<string, bool> */
    private function abilities(Prediction $p, User $actor, int $others, bool $endedSeason): array
    {
        return [
            'pick' => $actor->can('pick', $p) && ! $p->isLocked() && ! $p->isSettled(),
            'edit' => $p->canEdit($actor),
            'editOptions' => $p->canEditOptions($actor, $others),
            'editWording' => $p->canEditWording($actor, $others),
            'settle' => $p->canSettle($actor, $endedSeason),
            'void' => $p->canVoid($actor, $endedSeason),
            'delete' => $p->canDelete($actor, $others),
        ];
    }

    /** @return array{id: int, username: string, displayName: string, slug: string, avatarUrl: string|null} */
    public function user(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'username' => $user->username,
            'displayName' => $user->display_name,
            'slug' => $this->slugs->forResource(User::class)->toSlug($user),
            'avatarUrl' => $user->avatar_url,
        ];
    }
}
