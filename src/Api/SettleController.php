<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Refusal;
use ErnestDefoe\Predictions\Settler;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /api/predictions/{id}/settle   {optionId} | {value} | {void: true}
 *
 * The creator or a moderator. Settling again corrects it: every pick is
 * rescored and the season table rebuilt (Scorer).
 */
class SettleController extends Handler
{
    public function __construct(
        private readonly Settler $settler,
        private readonly Presenter $presenter,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $prediction = Find::visible($request, $actor);
        $body = self::body($request);
        if ($prediction->inEndedSeason()) {
            throw new Refusal('predictions_season_over');
        }
        if (! (! empty($body['void']) ? $prediction->canVoid($actor) : $prediction->canSettle($actor))) {
            self::deny();
        }

        $prediction = $this->settler->settle($prediction, $actor, Input::id($body['optionId'] ?? null), Input::number($body['value'] ?? null), ! empty($body['void']));

        return new JsonResponse(['data' => $this->presenter->single($prediction->fresh() ?? $prediction, $actor)]);
    }
}
