<?php

namespace ErnestDefoe\Predictions\Notification;

/** Alert: the member called it. */
class PredictionCalledBlueprint extends PredictionBlueprint
{
    public static function getType(): string
    {
        return 'predictionCalled';
    }
}
