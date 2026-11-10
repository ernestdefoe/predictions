<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Scores;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * DELETE /api/predictions/{id}: the creator while nobody else has picked, a
 * moderator any time. A scored one takes its points off the season table.
 */
class DeleteController extends Handler
{
    public function __construct(private readonly Scores $scores)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $prediction = Find::visible($request, $actor);

        $prediction->getConnection()->transaction(function () use ($prediction, $actor) {
            // Counted under the lock: a pick landing now must not slip past "nobody else picked".
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->firstOrFail();
            $others = Pick::query()->where('prediction_id', $row->id)->where('user_id', '<>', $actor->id)->count();
            if (! $row->canDelete($actor, $others)) {
                self::deny();
            }
            $this->scores->rebuildFor($row);
        });

        return new EmptyResponse(204);
    }
}
