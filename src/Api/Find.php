<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Refusal;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

final class Find
{
    /** The prediction in the route, if the actor may see it; a 404 otherwise, the same as one that doesn't exist. */
    public static function visible(ServerRequestInterface $request, User $actor): Prediction
    {
        $prediction = Prediction::query()->visibleTo($actor)->find((int) Arr::get($request->getQueryParams(), 'id'));
        if (! $prediction) {
            throw new Refusal('predictions_not_found', 404);
        }

        return $prediction;
    }
}
