<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Option;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\SlugManager;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Support\Collection;

/**
 * Predictions as the browser gets them.
 *
 * 🚨 A fixed number of queries for any number of cards: options, creators and
 * discussions are eager loaded, the viewer's own picks are one query, and the
 * "closest guesses" faces are one more. Nothing here loads per prediction.
 *
 * Other people's picks (option counts, guess stats) are left out while the
 * creator keeps them hidden and the prediction is still open.
 */
class Presenter
{
    public function __construct(
        private readonly SlugManager $slugs,
        private readonly UrlGenerator $url,
    ) {
    }

    /** @param Collection<int, Prediction> $predictions */
    public static function load(Collection $predictions): void
    {
        (new \Illuminate\Database\Eloquent\Collection($predictions->all()))->loadMissing(['options', 'user', 'discussion']);
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

        self::load($predictions);

        $mine = $actor->isGuest() ? collect() : Pick::query()
            ->where('user_id', $actor->id)
            ->whereIn('prediction_id', $predictions->pluck('id'))
            ->get()->keyBy('prediction_id');

        $closestIds = $predictions->flatMap(fn (Prediction $p) => array_column($p->stats['closest'] ?? [], 'userId'))->unique()->values();
        $closestUsers = $closestIds->isEmpty() ? collect() : User::query()->whereIn('id', $closestIds)->get()->keyBy('id');

        $canPick = $actor->hasPermission(Settings::PICK) && ! $actor->isGuest();
        // Which seasons are over, once for the page (a settled prediction in one can't be re-settled).
        $seasonIds = $predictions->pluck('season_id')->filter()->unique()->values();
        $ended = $seasonIds->isEmpty() ? [] : Season::query()->whereIn('id', $seasonIds)->whereNotNull('ended_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $predictions->map(fn (Prediction $p) => $this->one($p, $actor, $mine->get($p->id), $closestUsers, $canPick, in_array($p->season_id, $ended, true)))->values()->all();
    }

    /** @return array<string, mixed> */
    public function single(Prediction $prediction, User $actor): array
    {
        return $this->many(collect([$prediction]), $actor)[0];
    }

    /**
     * @param Collection<int, User> $closestUsers
     * @return array<string, mixed>
     */
    private function one(Prediction $p, User $actor, ?Pick $mine, Collection $closestUsers, bool $canPick, bool $endedSeason): array
    {
        $visible = $p->picksVisible();
        $settled = $p->isSettled();
        $void = $p->voided_at !== null;
        $others = $p->picks_count - ($mine ? 1 : 0);

        $stats = null;
        if ($p->type === Prediction::NUMBER && $visible && $p->stats) {
            $stats = $p->stats;
            $stats['closest'] = array_values(array_filter(array_map(function (array $row) use ($closestUsers) {
                $user = $closestUsers->get($row['userId']);

                return $user ? ['user' => $this->user($user), 'value' => $row['value'], 'points' => $row['points']] : null;
            }, $p->stats['closest'] ?? [])));
        }

        return [
            'id' => $p->id,
            'type' => $p->type,
            'title' => $p->title,
            'description' => $p->description,
            'unit' => $p->unit,
            'tolerance' => $p->tolerance,
            'hidePicks' => $p->hide_picks,
            'lockAt' => $p->lock_at->toIso8601String(),
            'status' => $p->status(),
            'picksVisible' => $visible,
            'picksCount' => $p->picks_count,
            'options' => $p->type === Prediction::NUMBER ? [] : $p->options->map(fn (Option $o) => [
                'id' => $o->id,
                'label' => $o->label,
                'count' => $visible ? $o->picks_count : null,
                'isCorrect' => $settled && ! $void ? $o->id === $p->correct_option_id : null,
            ])->values()->all(),
            'stats' => $stats,
            'answer' => $settled && ! $void ? $p->answer_value : null,
            'correctOptionId' => $settled && ! $void ? $p->correct_option_id : null,
            'settledAt' => $p->settled_at?->toIso8601String(),
            'scored' => $p->scored_at !== null,
            // Null until scored; false when too few picked for it to count on the leaderboard.
            'counted' => $p->scored_at !== null ? (bool) $p->counted : null,
            'minPickers' => Prediction::MIN_PICKERS,
            'myPick' => $mine?->toRow(),
            'user' => $p->user ? $this->user($p->user) : null,
            'discussion' => $p->discussion ? $this->discussion($p->discussion) : null,
            'url' => $this->url->to('forum')->route('predictions.show', ['id' => $p->id]),
            'createdAt' => $p->created_at->toIso8601String(),
            'can' => [
                'pick' => $canPick && ! $p->isLocked() && ! $settled,
                'edit' => $p->canEdit($actor),
                'editOptions' => $p->canEditOptions($actor, $others),
                'editWording' => $p->canEditWording($actor, $others),
                'settle' => $p->canSettle($actor, $endedSeason),
                'void' => $p->canVoid($actor, $endedSeason),
                'delete' => $p->canDelete($actor, $others),
            ],
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

    /** @return array{id: int, title: string, slug: string} */
    private function discussion(Discussion $discussion): array
    {
        return [
            'id' => (int) $discussion->id,
            'title' => $discussion->title,
            'slug' => $this->slugs->forResource(Discussion::class)->toSlug($discussion),
        ];
    }
}
