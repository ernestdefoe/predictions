<?php

namespace ErnestDefoe\Predictions\Console;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Notification\PredictionLockedBlueprint;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Scorer;
use Flarum\Console\AbstractCommand;
use Flarum\Notification\NotificationSyncer;

/**
 * Every minute (the scheduler):
 *  1. stamps predictions whose lock time has passed and tells each creator
 *     it's time to settle. Picks never wait for this: they are judged by the
 *     lock time itself, under a row lock (Picks).
 *  2. scores any settled prediction the queue hasn't (a worker that died, a
 *     forum without one running). Scorer is idempotent, so a duplicate is free.
 *
 * Each stamp is a conditional UPDATE (… AND locked_at IS NULL): of two runs
 * that overlap, exactly one gets the row and sends the alert.
 */
class TickCommand extends AbstractCommand
{
    public function __construct(
        private readonly Scorer $scorer,
        private readonly NotificationSyncer $notifications,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('predictions:tick')
            ->setDescription('Lock predictions whose time is up and score settled ones still waiting.');
    }

    protected function fire(): int
    {
        $now = Carbon::now();
        $locked = 0;

        $due = Prediction::query()->whereNull('locked_at')->where('lock_at', '<=', $now)->orderBy('lock_at')->limit(500)->pluck('id');
        foreach ($due as $id) {
            if (Prediction::query()->whereKey($id)->whereNull('locked_at')->update(['locked_at' => $now]) !== 1) {
                continue;
            }
            $locked++;

            $prediction = Prediction::query()->with('user')->find($id);
            if ($prediction && $prediction->user && ! $prediction->isSettled()) {
                $this->notifications->sync(new PredictionLockedBlueprint($prediction), [$prediction->user]);
            }
        }

        $waiting = Prediction::query()
            ->whereNotNull('settled_at')
            ->whereNull('scored_at')
            ->limit(100)
            ->pluck('id');
        foreach ($waiting as $id) {
            $this->scorer->score((int) $id);
        }

        $this->info("Locked {$locked}, scored {$waiting->count()}.");

        return 0;
    }
}
