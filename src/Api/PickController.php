<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Picks;
use ErnestDefoe\Predictions\Settings;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /api/predictions/{id}/pick   {optionId} or {value}: make or change a pick until the lock
 * DELETE /api/predictions/{id}/pick   take it back until the lock
 */
class PickController extends Handler
{
    public function __construct(
        private readonly Picks $picks,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan(Settings::PICK);
        $prediction = Find::visible($request, $actor);

        if ($request->getMethod() === 'DELETE') {
            $prediction = $this->picks->withdraw($prediction, $actor);
        } else {
            $body = self::body($request);
            $prediction = $this->picks->pick($prediction, $actor, Input::id($body['optionId'] ?? null), Input::number($body['value'] ?? null));
        }

        return new JsonResponse(['data' => $this->presenter->single($prediction, $actor)]);
    }
}
