<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Api\Input;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Opening, editing and deleting a prediction (PredictionResource's Create,
 * Update and Delete). The resource's fields have validated and set the input
 * on the model; this checks what depends on the whole row.
 *
 * The creator edits while it's open, a moderator until it's settled. The
 * choices (and the kind) only change while nobody else has picked; once
 * someone has, the question and hidden picks only change by a moderator's
 * hand, and only a moderator can push the lock later. "Someone else has
 * picked" is always counted under the prediction's RowLock.
 */
class Editor
{
    /** The columns an edit may change (each was validated by its field). */
    private const EDITABLE = ['type', 'title', 'description', 'unit', 'tolerance', 'hide_picks', 'lock_at'];

    public function __construct(
        private readonly RowLock $lock,
        private readonly Scores $scores,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    public function create(Prediction $prediction, User $actor): Prediction
    {
        $actor->assertCan(Settings::VIEW);
        $this->assertMayAttach($prediction->discussion_id, $actor);

        $prediction->user_id = $actor->id;
        $prediction->lock_at = Input::lockAt($prediction->lock_at);
        $labels = $this->labels($prediction);
        if ($prediction->type !== Prediction::NUMBER) {
            $prediction->unit = null;
            $prediction->tolerance = null;
        }

        // One create per member at a time, so two at once can't both slip under the daily limit.
        $this->lock->run('member.'.$actor->id, function () use ($prediction, $labels, $actor) {
            $this->assertUnderDailyLimit($actor);
            $prediction->save();
            $this->replaceOptions($prediction, $labels);
        });

        return $prediction;
    }

    public function update(Prediction $edited, User $actor): Prediction
    {
        // What the request changed, read through the model's casts (getDirty() holds raw column values).
        $changes = [];
        foreach (array_intersect(array_keys($edited->getDirty()), self::EDITABLE) as $key) {
            $changes[$key] = $edited->getAttribute($key);
        }

        return $this->lock->run('prediction.'.$edited->id, function () use ($edited, $changes, $actor) {
            // Re-read and count under the lock: a pick or a settle may have landed since the endpoint's check.
            $row = Prediction::query()->whereKey($edited->id)->lockForUpdate()->firstOrFail();
            if (! $row->canEdit($actor)) {
                throw new PermissionDeniedException();
            }
            $others = $this->others($row, $actor);

            $this->wording($row, $actor, $changes, $others);
            $this->lockTime($row, $actor, $changes, $others, $edited->lockNow);
            $labels = $this->kind($row, $actor, $changes, $edited->pendingLabels, $others);
            $row->save();

            if ($labels !== null) {
                $this->replaceOptions($row, $labels);
                // The actor's own pick (the only one there can be) pointed at the old choices.
                Pick::query()->where('prediction_id', $row->id)->delete();
                $row->picks_count = 0;
                $row->stats = null;
                $row->save();
            }

            return $row;
        });
    }

    /** The creator while nobody else has picked; a moderator any time. A scored one takes its points with it. */
    public function delete(Prediction $prediction, User $actor): void
    {
        $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $actor) {
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->firstOrFail();
            if (! $row->canDelete($actor, $this->others($row, $actor))) {
                throw new PermissionDeniedException();
            }
            $this->scores->rebuildFor($row);
        });
    }

    private function others(Prediction $row, User $actor): int
    {
        return Pick::query()->where('prediction_id', $row->id)->where('user_id', '<>', $actor->id)->count();
    }

    private function assertUnderDailyLimit(User $actor): void
    {
        if ($actor->hasPermission(Settings::MODERATE)) {
            return;
        }
        $today = Prediction::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subDay())->count();
        if ($today >= (int) $this->settings->get(Settings::DAILY_LIMIT)) {
            throw new Refusal('predictions_slow_down', 429);
        }
    }

    /**
     * Attached only to a discussion the member started (or any, for a moderator)
     * and can reply to. The relationship field has already checked it's visible.
     */
    private function assertMayAttach(?int $discussionId, User $actor): void
    {
        if ($discussionId === null) {
            return;
        }

        $discussion = Discussion::query()->whereVisibleTo($actor)->find($discussionId);
        if (! $discussion || ! $actor->can('reply', $discussion)
            || ($discussion->user_id !== $actor->id && ! $actor->hasPermission(Settings::MODERATE))) {
            throw new Refusal('predictions_bad_discussion');
        }
    }

    /**
     * The question, its details and whether picks are hidden. Once others have
     * picked, the question they answered (and hidden picks) only changes by a
     * moderator's hand; sending them unchanged is fine.
     *
     * @param array<string, mixed> $changes
     */
    private function wording(Prediction $row, User $actor, array $changes, int $others): void
    {
        $frozen = ! $row->canEditWording($actor, $others);
        if (array_key_exists('title', $changes)) {
            if ($frozen && $changes['title'] !== $row->title) {
                throw new Refusal('predictions_frozen');
            }
            $row->title = $changes['title'];
        }
        if (array_key_exists('description', $changes)) {
            $row->description = $changes['description'];
        }
        if (array_key_exists('hide_picks', $changes) && ! $row->isLocked()) {
            if ($frozen && (bool) $changes['hide_picks'] !== $row->hide_picks) {
                throw new Refusal('predictions_frozen');
            }
            $row->hide_picks = (bool) $changes['hide_picks'];
        }
    }

    /** @param array<string, mixed> $changes */
    private function lockTime(Prediction $row, User $actor, array $changes, int $others, bool $now): void
    {
        if ($now) {
            $row->lock_at = Carbon::now();
        } elseif (array_key_exists('lock_at', $changes) && ! $row->isLocked()) {
            $lockAt = Input::lockAt($changes['lock_at']);
            // Once others have picked, only a moderator can push the lock later: a creator
            // could otherwise keep picks open after the game started and pick knowing the score.
            if ($others > 0 && $lockAt->gt($row->lock_at) && ! $row->canModerate($actor)) {
                throw new Refusal('predictions_lock_later');
            }
            $row->lock_at = $lockAt;
        }
    }

    /**
     * The kind, the choices, the unit and the range: only while nobody else has picked.
     * Returns the new choices when they change (null when they don't).
     *
     * @param array<string, mixed> $changes
     * @param list<string>|null $pending
     * @return list<string>|null
     */
    private function kind(Prediction $row, User $actor, array $changes, ?array $pending, int $others): ?array
    {
        // The form sends the choices back with every edit: the same list in the same order is no change.
        if ($pending !== null && ! array_key_exists('type', $changes)
            && Input::options($pending) === $row->options()->orderBy('position')->pluck('label')->all()) {
            $pending = null;
        }
        if (! array_intersect(['type', 'unit', 'tolerance'], array_keys($changes)) && $pending === null) {
            return null;
        }
        if (! $row->canEditOptions($actor, $others)) {
            throw new Refusal('predictions_has_picks');
        }

        $labels = null;
        $type = $changes['type'] ?? $row->type;
        if ($type !== $row->type || $pending !== null) {
            $row->type = $type;
            $row->pendingLabels = $pending ?? $row->options()->pluck('label')->all();
            $labels = $this->labels($row);
        }
        $number = $row->type === Prediction::NUMBER;
        $row->unit = $number ? ($changes['unit'] ?? $row->unit) : null;
        $row->tolerance = $number ? (array_key_exists('tolerance', $changes) ? $changes['tolerance'] : $row->tolerance) : null;

        return $labels;
    }

    /** @return list<string> */
    private function labels(Prediction $prediction): array
    {
        return match ($prediction->type) {
            Prediction::YESNO => ['Yes', 'No'],
            Prediction::NUMBER => [],
            default => Input::options($prediction->pendingLabels),
        };
    }

    /** @param list<string> $labels */
    private function replaceOptions(Prediction $prediction, array $labels): void
    {
        Option::query()->where('prediction_id', $prediction->id)->delete();
        if ($labels) {
            Option::query()->insert(array_map(
                fn (string $label, int $i) => ['prediction_id' => $prediction->id, 'label' => $label, 'position' => $i, 'picks_count' => 0],
                $labels,
                array_keys($labels),
            ));
        }
    }
}
