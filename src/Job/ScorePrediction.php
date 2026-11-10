<?php

namespace ErnestDefoe\Predictions\Job;

use ErnestDefoe\Predictions\Scorer;
use Flarum\Queue\AbstractJob;

class ScorePrediction extends AbstractJob
{
    public function __construct(public readonly int $predictionId)
    {
    }

    public function handle(Scorer $scorer): void
    {
        $scorer->score($this->predictionId);
    }
}
