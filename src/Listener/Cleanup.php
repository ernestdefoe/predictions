<?php

namespace ErnestDefoe\Predictions\Listener;

use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Picks;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Scores;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\User\Event\Deleting as UserDeleting;

/**
 * Before a discussion or a member goes.
 *
 * 🚨 A discussion's predictions are deleted with it (and their points come off
 * the season table): a prediction is only ever as visible as its discussion,
 * and one left behind without it would turn public. The foreign key cascades
 * too, where the database enforces it.
 *
 * A member's picks go, and every prediction they picked is recounted so its
 * totals and number stats stay right. Their own predictions stay, unowned.
 */
class Cleanup
{
    public function __construct(
        private readonly Scores $scores,
        private readonly Picks $picks,
    ) {
    }

    public function discussion(DiscussionDeleting $event): void
    {
        foreach (Prediction::query()->where('discussion_id', $event->discussion->id)->get() as $prediction) {
            $prediction->getConnection()->transaction(fn () => $this->scores->rebuildFor($prediction));
        }
    }

    public function user(UserDeleting $event): void
    {
        $id = $event->user->id;
        $picked = Pick::query()->where('user_id', $id)->pluck('prediction_id')->all();

        Pick::query()->where('user_id', $id)->delete();
        Score::query()->where('user_id', $id)->delete();
        Prediction::query()->where('user_id', $id)->update(['user_id' => null]);

        foreach (Prediction::query()->whereIn('id', $picked ?: [0])->get() as $prediction) {
            $this->picks->recount($prediction);
        }
    }
}
