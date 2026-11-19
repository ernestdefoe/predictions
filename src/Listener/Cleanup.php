<?php

namespace ErnestDefoe\Predictions\Listener;

use ErnestDefoe\Predictions\Job\RefreshNumberStats;
use ErnestDefoe\Predictions\Option;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Scores;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\User\Event\Deleting as UserDeleting;
use Illuminate\Contracts\Queue\Queue;

/**
 * Before a discussion or a member goes.
 *
 * 🚨 A discussion's predictions are deleted with it (and their points come off
 * the season table): a prediction is only ever as visible as its discussion,
 * and one left behind without it would turn public. The foreign key cascades
 * too, where the database enforces it.
 *
 * A member's picks go and the counts they were in drop by one (a member has at
 * most one pick per prediction, so that's one UPDATE for the predictions and
 * one for the choices, however many they picked). Closed number predictions get
 * their stats re-worked by a queued job. Their own predictions stay, unowned.
 */
class Cleanup
{
    public function __construct(
        private readonly Scores $scores,
        private readonly Queue $queue,
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

        Pick::query()->where('user_id', $id)->select(['id', 'prediction_id', 'option_id'])
            ->chunkById(500, function ($picks) {
                $predictionIds = $picks->pluck('prediction_id')->all();
                $optionIds = $picks->pluck('option_id')->filter()->values()->all();

                // Atomic, like a pick taken back, so a pick landing at the same moment isn't lost.
                Prediction::query()->whereIn('id', $predictionIds)->where('picks_count', '>', 0)->decrement('picks_count');
                if ($optionIds) {
                    Option::query()->whereIn('id', $optionIds)->where('picks_count', '>', 0)->decrement('picks_count');
                }
                $closed = Prediction::query()->whereIn('id', $predictionIds)->where('type', Prediction::NUMBER)->whereNotNull('locked_at')->pluck('id')->map(fn ($id) => (int) $id)->all();

                Pick::query()->whereIn('id', $picks->modelKeys())->delete();
                if ($closed) {
                    $this->queue->push(new RefreshNumberStats($closed));
                }
            });

        $this->scores->forgetMember($id);
        Prediction::query()->where('user_id', $id)->update(['user_id' => null]);
    }
}
