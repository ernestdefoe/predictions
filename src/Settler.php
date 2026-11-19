<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Job\ScorePrediction;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;

/**
 * Settling (or voiding) a prediction: the answer is written under the row's
 * RowLock, then the scoring runs as a queued job. Settling again (a
 * moderator's correction) clears scored_at and queues it again; the job
 * rescores every pick from scratch, so it never matters how many times it
 * runs or in what order.
 */
class Settler
{
    public function __construct(
        private readonly Queue $queue,
        private readonly RowLock $lock,
    ) {
    }

    public function settle(Prediction $prediction, User $actor, ?int $optionId, ?float $value, bool $void): Prediction
    {
        $settled = $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $actor, $optionId, $value, $void) {
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->first();
            if (! $row) {
                throw new Refusal('predictions_gone', 404);
            }
            // Checked again under the lock: a creator's double click must not settle it twice.
            $this->authorize($row, $actor, $void);

            $this->answer($row, $optionId, $value, $void);
            $this->closePicks($row);
            $row->settled_by_id = $actor->id;
            $row->season_id ??= Season::current()->id;
            $row->scored_at = null;
            $row->save();

            return $row;
        });

        $this->queue->push(new ScorePrediction($settled->id));

        return $settled;
    }

    private function authorize(Prediction $row, User $actor, bool $void): void
    {
        if ($row->inEndedSeason()) {
            throw new Refusal('predictions_season_over');
        }
        if (! ($void ? $row->canVoid($actor) : $row->canSettle($actor))) {
            throw new PermissionDeniedException();
        }
    }

    private function answer(Prediction $row, ?int $optionId, ?float $value, bool $void): void
    {
        $row->correct_option_id = null;
        $row->answer_value = null;
        $row->voided_at = $void ? Carbon::now() : null;
        $row->settled_at = Carbon::now();

        if ($void) {
            return;
        }
        [$row->correct_option_id, $row->answer_value] = $row->checkedAnswer($optionId, $value);
    }

    /**
     * Settling early (the game ended at halftime, the award was announced a
     * day early) also closes picks, here, under the same lock.
     */
    private function closePicks(Prediction $row): void
    {
        $now = Carbon::now();
        if ($row->lock_at->gt($now)) {
            $row->lock_at = $now;
        }
        $row->locked_at ??= $now;
    }
}
