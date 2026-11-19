<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Api\Input;
use Flarum\Discussion\Discussion;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;

/**
 * Opening, editing and deleting a prediction (the resource's Create, Update
 * and Delete endpoints hand their attributes here).
 *
 * The creator edits while it's open, a moderator until it's settled. The
 * choices (and the type) only change while nobody else has picked; once
 * someone has, the question and hidden picks only change by a moderator's
 * hand, and only a moderator can push the lock later. "Someone else has
 * picked" is always counted under the prediction's RowLock.
 */
class Editor
{
    public function __construct(
        private readonly RowLock $lock,
        private readonly Picks $picks,
        private readonly Scores $scores,
        private readonly SettingsRepositoryInterface $settings,
    ) {
    }

    /** @param array<string, mixed> $input */
    public function create(Prediction $prediction, User $actor, array $input): Prediction
    {
        $actor->assertCan(Settings::VIEW);
        $this->assertUnderDailyLimit($actor);

        $prediction->user_id = $actor->id;
        $prediction->type = Input::type($input['type'] ?? null);
        $prediction->title = Input::title($input['title'] ?? null);
        $prediction->description = Input::description($input['description'] ?? null);
        $prediction->lock_at = Input::lockAt($input['lockAt'] ?? null);
        $prediction->hide_picks = (bool) ($input['hidePicks'] ?? false);
        $prediction->discussion_id = $this->discussion($input['discussionId'] ?? null, $actor);
        $labels = $this->labels($prediction, $input);
        if ($prediction->type === Prediction::NUMBER) {
            $prediction->unit = Input::unit($input['unit'] ?? null);
            $prediction->tolerance = Input::tolerance($input['tolerance'] ?? null);
        }

        $prediction->getConnection()->transaction(function () use ($prediction, $labels) {
            $prediction->save();
            $this->replaceOptions($prediction, $labels);
        });

        return $prediction;
    }

    /** @param array<string, mixed> $input */
    public function update(Prediction $prediction, User $actor, array $input): Prediction
    {
        return $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $actor, $input) {
            // Re-read and count under the lock: a pick or a settle may have landed since the endpoint's check.
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->firstOrFail();
            if (! $row->canEdit($actor)) {
                throw new PermissionDeniedException();
            }
            $others = $this->others($row, $actor);

            $this->wording($row, $actor, $input, $others);
            $this->lockTime($row, $actor, $input, $others);
            if (array_key_exists('discussionId', $input)) {
                $row->discussion_id = $this->discussion($input['discussionId'], $actor);
            }
            $labels = $this->kind($row, $actor, $input, $others);
            $row->save();

            if ($labels !== null) {
                $this->replaceOptions($row, $labels);
                // The actor's own pick (the only one there can be) pointed at the old choices.
                Pick::query()->where('prediction_id', $row->id)->delete();
                $this->picks->recount($row);
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
     * The question, its details and whether picks are hidden. Once others have
     * picked, the question they answered (and hidden picks) only changes by a
     * moderator's hand; sending them unchanged is fine.
     *
     * @param array<string, mixed> $input
     */
    private function wording(Prediction $row, User $actor, array $input, int $others): void
    {
        $frozen = ! $row->canEditWording($actor, $others);
        if (array_key_exists('title', $input)) {
            $title = Input::title($input['title']);
            if ($frozen && $title !== $row->title) {
                throw new Refusal('predictions_frozen');
            }
            $row->title = $title;
        }
        if (array_key_exists('description', $input)) {
            $row->description = Input::description($input['description']);
        }
        if (array_key_exists('hidePicks', $input) && ! $row->isLocked()) {
            if ($frozen && (bool) $input['hidePicks'] !== $row->hide_picks) {
                throw new Refusal('predictions_frozen');
            }
            $row->hide_picks = (bool) $input['hidePicks'];
        }
    }

    /** @param array<string, mixed> $input */
    private function lockTime(Prediction $row, User $actor, array $input, int $others): void
    {
        if (! empty($input['lockNow'])) {
            $row->lock_at = Carbon::now();
        } elseif (array_key_exists('lockAt', $input) && ! $row->isLocked()) {
            $lockAt = Input::lockAt($input['lockAt']);
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
     * @param array<string, mixed> $input
     * @return list<string>|null
     */
    private function kind(Prediction $row, User $actor, array $input, int $others): ?array
    {
        if (! array_intersect(['options', 'type', 'unit', 'tolerance'], array_keys($input))) {
            return null;
        }
        if (! $row->canEditOptions($actor, $others)) {
            throw new Refusal('predictions_has_picks');
        }

        $labels = null;
        $type = array_key_exists('type', $input) ? Input::type($input['type']) : $row->type;
        if ($type !== $row->type || array_key_exists('options', $input)) {
            $row->type = $type;
            $labels = $this->labels($row, $input + ['options' => $row->options()->pluck('label')->all()]);
        }
        $number = $row->type === Prediction::NUMBER;
        $row->unit = $number ? Input::unit($input['unit'] ?? $row->unit) : null;
        $row->tolerance = $number ? Input::tolerance(array_key_exists('tolerance', $input) ? $input['tolerance'] : $row->tolerance) : null;

        return $labels;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<string>
     */
    private function labels(Prediction $prediction, array $input): array
    {
        return match ($prediction->type) {
            Prediction::YESNO => ['Yes', 'No'],
            Prediction::NUMBER => [],
            default => Input::options($input['options'] ?? null),
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

    /** Attach to a discussion its author (or a moderator) can see and reply to. Null detaches. */
    private function discussion(mixed $value, User $actor): ?int
    {
        $id = Input::id($value);
        if ($id === null) {
            return null;
        }

        $discussion = Discussion::query()->whereVisibleTo($actor)->find($id);
        if (! $discussion || ! $actor->can('reply', $discussion)
            || ($discussion->user_id !== $actor->id && ! $actor->hasPermission(Settings::MODERATE))) {
            throw new Refusal('predictions_bad_discussion');
        }

        return $discussion->id;
    }
}
