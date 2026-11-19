<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use Flarum\User\User;
use Psr\Log\LoggerInterface;

/**
 * Making, changing and taking back a pick, and recounting a prediction.
 *
 * 🚨 Race-safe: under the prediction's RowLock the row is re-read (SELECT ...
 * FOR UPDATE), and the lock time and settle state are checked INSIDE the
 * transaction, so a pick that arrives at 7:59:59.999 against an 8:00 lock, or
 * alongside a settle, is judged against the row as it is at that moment. One
 * pick per member per prediction is also a unique key.
 *
 * Counts move by one per pick (atomic increments under the lock), so a pick
 * costs the same however many came before it. Number stats are worked out once
 * picks close (predictions:tick, Scorer, refreshStats), not on every guess.
 */
class Picks
{
    public function __construct(
        private readonly RowLock $lock,
        private readonly LoggerInterface $log,
    ) {
    }

    public function pick(Prediction $prediction, User $actor, ?int $optionId, ?float $value): Prediction
    {
        return $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $actor, $optionId, $value) {
            $locked = $this->lockRow($prediction);
            [$optionId, $value] = $locked->checkedAnswer($optionId, $value);

            $pick = Pick::query()->where('prediction_id', $locked->id)->where('user_id', $actor->id)->first();
            $previous = $pick?->option_id;
            if (! $pick) {
                $pick = new Pick();
                $pick->prediction_id = $locked->id;
                $pick->user_id = $actor->id;
                $locked->increment('picks_count');
            }
            $pick->option_id = $optionId;
            $pick->value = $value;
            $pick->save();

            if ($optionId !== $previous) {
                $this->move($previous, $optionId);
            }

            return $locked;
        });
    }

    public function withdraw(Prediction $prediction, User $actor): Prediction
    {
        return $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $actor) {
            $locked = $this->lockRow($prediction);
            $pick = Pick::query()->where('prediction_id', $locked->id)->where('user_id', $actor->id)->first();
            if ($pick) {
                $pick->delete();
                $locked->decrement('picks_count');
                $this->move($pick->option_id, null);
            }

            return $locked;
        });
    }

    /**
     * Works out the stats (count, average, median, range, histogram) of number
     * predictions whose picks have closed: predictions:tick at the lock, and the
     * queued job after a member who picked is deleted. Every guess for the batch
     * is read in one chunked query; each prediction then gets one write, keeping
     * the "closest" list the scorer added.
     *
     * @param list<int> $ids
     */
    public function refreshStats(array $ids): void
    {
        $predictions = Prediction::query()->whereIn('id', $ids ?: [0])->where('type', Prediction::NUMBER)->get()
            ->filter(fn (Prediction $p) => $p->isLocked() || $p->isSettled());
        if ($predictions->isEmpty()) {
            return;
        }

        $values = [];
        Pick::query()->whereIn('prediction_id', $predictions->modelKeys())->whereNotNull('value')
            ->select(['id', 'prediction_id', 'value'])
            ->chunkById(1000, function ($picks) use (&$values) {
                foreach ($picks as $pick) {
                    $values[$pick->prediction_id][] = (float) $pick->value;
                }
            });

        foreach ($predictions as $prediction) {
            $stats = Scoring::stats($values[$prediction->id] ?? []);
            try {
                // Under the row's lock, re-read, so a scorer writing "closest" at the same moment isn't overwritten.
                $this->lock->run('prediction.'.$prediction->id, function () use ($prediction, $stats) {
                    $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->first();
                    if ($row) {
                        $closest = $row->stats['closest'] ?? null;
                        $row->stats = $stats && $closest !== null ? $stats + ['closest' => $closest] : $stats;
                        $row->save();
                    }
                });
            } catch (Refusal $busy) {
                if ($busy->reason !== 'predictions_busy') {
                    throw $busy;
                }
                // Busy means it's being settled or scored right now, and the scorer writes the stats too.
                $this->log->info("Predictions: prediction {$prediction->id} is busy, its stats come with its scoring.");
            }
        }
    }

    /** A pick moved from one choice to another (or to or from none). */
    private function move(?int $from, ?int $to): void
    {
        if ($from !== null) {
            Option::query()->whereKey($from)->where('picks_count', '>', 0)->decrement('picks_count');
        }
        if ($to !== null) {
            Option::query()->whereKey($to)->increment('picks_count');
        }
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
