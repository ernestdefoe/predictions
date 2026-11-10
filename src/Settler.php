<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Job\ScorePrediction;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\User\User;
use Illuminate\Contracts\Queue\Queue;

/**
 * Settling (or voiding) a prediction: the answer is written under a row lock,
 * then the scoring runs as a queued job. Settling again (a correction) clears
 * scored_at and queues it again; the job rescores every pick from scratch, so
 * it never matters how many times it runs or in what order.
 */
class Settler
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function settle(Prediction $prediction, User $actor, ?int $optionId, ?float $value, bool $void): Prediction
    {
        $settled = $prediction->getConnection()->transaction(function () use ($prediction, $actor, $optionId, $value, $void) {
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->first();
            if (! $row) {
                throw new Refusal('predictions_gone', 404);
            }
            // Checked again under the lock: a creator's double click must not settle it twice.
            if ($row->inEndedSeason()) {
                throw new Refusal('predictions_season_over');
            }
            if (! ($void ? $row->canVoid($actor) : $row->canSettle($actor))) {
                throw new PermissionDeniedException();
            }

            $row->correct_option_id = null;
            $row->answer_value = null;
            $row->settled_at = null;
            $row->voided_at = null;

            if ($void) {
                $row->voided_at = Carbon::now();
                $row->settled_at = Carbon::now();
            } elseif ($row->type === Prediction::NUMBER) {
                if ($value === null || ! is_finite($value) || abs($value) > 1e12) {
                    throw new Refusal('predictions_bad_value');
                }
                $row->answer_value = $value;
                $row->settled_at = Carbon::now();
            } else {
                if ($optionId === null || ! $row->options()->whereKey($optionId)->exists()) {
                    throw new Refusal('predictions_bad_option');
                }
                $row->correct_option_id = $optionId;
                $row->settled_at = Carbon::now();
            }

            // Settling early (the game ended at halftime, the award was announced
            // a day early) also closes picks, here, under the same lock.
            $now = Carbon::now();
            if ($row->lock_at->gt($now)) {
                $row->lock_at = $now;
            }
            $row->locked_at ??= $now;
            $row->settled_by_id = $actor->id;
            $row->season_id ??= Season::current()->id;
            $row->scored_at = null;
            $row->save();

            return $row;
        });

        $this->queue->push(new ScorePrediction($settled->id));

        return $settled;
    }
}
