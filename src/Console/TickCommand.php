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
 * The stamp is a conditional UPDATE (… AND locked_at IS NULL), and the
 * schedule runs withoutOverlapping(), so each lock is announced once.
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

        $locked = $this->lockDue($now);

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

    /**
     * Stamps up to 500 due predictions in one UPDATE (only rows still unstamped), then
     * tells each creator whose prediction is still unsettled. Three queries for the
     * batch, then one alert each (each alert is about a different prediction).
     */
    private function lockDue(Carbon $now): int
    {
        $due = Prediction::query()->whereNull('locked_at')->where('lock_at', '<=', $now)->orderBy('lock_at')->limit(500)->pluck('id');
        if ($due->isEmpty()) {
            return 0;
        }

        Prediction::query()->whereIn('id', $due)->whereNull('locked_at')->update(['locked_at' => $now]);

        $stamped = Prediction::query()->whereIn('id', $due)->where('locked_at', $now)->whereNull('settled_at')->with('user')->get();
        foreach ($stamped as $prediction) {
            if ($prediction->user) {
                $this->notifications->sync(new PredictionLockedBlueprint($prediction), [$prediction->user]);
            }
        }

        return $stamped->count();
    }
}
