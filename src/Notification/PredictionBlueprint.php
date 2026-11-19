<?php

namespace ErnestDefoe\Predictions\Notification;

use ErnestDefoe\Predictions\Prediction;
use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;

/**
 * An alert about a prediction; each kind only names its type. The subject is
 * the prediction, which has an API resource (Api\PredictionResource): a
 * subject without one 500s every notification list. No data and no sender,
 * so a correction's sync() matches (and takes back) the earlier alert.
 */
abstract class PredictionBlueprint implements BlueprintInterface, AlertableInterface
{
    public function __construct(public Prediction $prediction)
    {
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->prediction;
    }

    public function getData(): mixed
    {
        return null;
    }

    public static function getSubjectModel(): string
    {
        return Prediction::class;
    }
}
