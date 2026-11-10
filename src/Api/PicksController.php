<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/predictions/{id}/picks: who picked what, once picks are out in
 * the open (after the lock, or before it if the creator allowed it).
 * Two queries: the picks, and their members.
 */
class PicksController extends Handler
{
    public const MAX = 300;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $prediction = Find::visible($request, $actor);
        if (! $prediction->picksVisible()) {
            return new JsonResponse(['data' => [], 'hidden' => true]);
        }

        $query = Pick::query()->where('prediction_id', $prediction->id)->with('user');
        if ($prediction->type === Prediction::NUMBER) {
            $query->orderByDesc('points')->orderBy('value');
        } else {
            $query->orderByDesc('points')->orderBy('created_at');
        }
        $picks = $query->limit(self::MAX)->get();

        return new JsonResponse([
            'data' => $picks->filter(fn (Pick $pick) => $pick->user !== null)->map(fn (Pick $pick) => [
                'user' => $this->presenter->user($pick->user),
                'at' => $pick->created_at->toIso8601String(),
            ] + $pick->toRow())->values()->all(),
            'hidden' => false,
            'more' => $prediction->picks_count > self::MAX,
        ]);
    }
}
