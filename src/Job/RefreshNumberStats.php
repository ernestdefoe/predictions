<?php

namespace ErnestDefoe\Predictions\Job;

use ErnestDefoe\Predictions\Picks;
use Flarum\Queue\AbstractJob;

/** Re-works the stats of closed number predictions after picks went away (a member was deleted). */
class RefreshNumberStats extends AbstractJob
{
    /** @param list<int> $predictionIds */
    public function __construct(public readonly array $predictionIds)
    {
    }

    public function handle(Picks $picks): void
    {
        $picks->refreshStats($this->predictionIds);
    }
}
