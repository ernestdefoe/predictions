<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Pick;
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
        $others = Pick::query()->where('prediction_id', $prediction->id)->where('user_id', '<>', $actor->id)->count();
        if (! $prediction->canDelete($actor, $others)) {
            self::deny();
        }

        $prediction->getConnection()->transaction(fn () => $this->scores->rebuildFor($prediction));

        return new EmptyResponse(204);
    }
}
