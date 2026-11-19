<?php

namespace ErnestDefoe\Predictions\Notification;

/** Alert: the creator's prediction locked: time to settle it. */
class PredictionLockedBlueprint extends PredictionBlueprint
{
    public static function getType(): string
    {
        return 'predictionLocked';
    }
}
