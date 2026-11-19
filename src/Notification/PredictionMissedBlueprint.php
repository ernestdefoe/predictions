<?php

namespace ErnestDefoe\Predictions\Notification;

/** Alert: the prediction is settled and the member's pick missed. */
class PredictionMissedBlueprint extends PredictionBlueprint
{
    public static function getType(): string
    {
        return 'predictionMissed';
    }
}
