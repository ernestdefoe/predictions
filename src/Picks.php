<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use Flarum\User\User;

/**
 * Making, changing and taking back a pick.
 *
 * 🚨 Race-safe: the prediction row is locked (SELECT ... FOR UPDATE) and the
 * lock time and settle state are re-read INSIDE the transaction, so a pick
 * that arrives at 7:59:59.999 against a 8:00 lock, or alongside a settle, is
 * judged against the row as it is at that moment, never against a copy read
 * earlier. One pick per member per prediction is also a unique key.
 */
class Picks
{
    public function pick(Prediction $prediction, User $actor, ?int $optionId, ?float $value): Prediction
    {
        return $prediction->getConnection()->transaction(function () use ($prediction, $actor, $optionId, $value) {
            $locked = $this->lockRow($prediction);

            if ($locked->type === Prediction::NUMBER) {
                if ($value === null || ! is_finite($value) || abs($value) > 1e12) {
                    throw new Refusal('predictions_bad_value');
                }
                $optionId = null;
            } else {
                if ($optionId === null || ! $locked->options()->whereKey($optionId)->exists()) {
                    throw new Refusal('predictions_bad_option');
                }
                $value = null;
            }

            $pick = Pick::query()->where('prediction_id', $locked->id)->where('user_id', $actor->id)->first() ?? new Pick();
            $pick->prediction_id = $locked->id;
            $pick->user_id = $actor->id;
            $pick->option_id = $optionId;
            $pick->value = $value;
            $pick->save();

            $this->recount($locked);

            return $locked;
        });
    }

    public function withdraw(Prediction $prediction, User $actor): Prediction
    {
        return $prediction->getConnection()->transaction(function () use ($prediction, $actor) {
            $locked = $this->lockRow($prediction);
            Pick::query()->where('prediction_id', $locked->id)->where('user_id', $actor->id)->delete();
            $this->recount($locked);

            return $locked;
        });
    }

    /** Totals are recounted from the picks, not incremented, so they can never drift. */
    public function recount(Prediction $prediction): void
    {
        $prediction->picks_count = Pick::query()->where('prediction_id', $prediction->id)->count();

        if ($prediction->type === Prediction::NUMBER) {
            $values = Pick::query()->where('prediction_id', $prediction->id)->whereNotNull('value')->pluck('value')->map(fn ($v) => (float) $v)->all();
            $prediction->stats = Scoring::stats(array_values($values));
        } else {
            $counts = Pick::query()->where('prediction_id', $prediction->id)
                ->selectRaw('option_id, COUNT(*) as aggregate')->groupBy('option_id')->pluck('aggregate', 'option_id');

            foreach ($prediction->options()->get() as $option) {
                $count = (int) ($counts[$option->id] ?? 0);
                if ($option->picks_count !== $count) {
                    $option->picks_count = $count;
                    $option->save();
                }
            }
        }

        $prediction->save();
    }

    private function lockRow(Prediction $prediction): Prediction
    {
        $locked = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->first();

        if (! $locked || $locked->isSettled() || $locked->lock_at->lte(Carbon::now())) {
            throw new Refusal('predictions_locked');
        }

        return $locked;
    }
}
