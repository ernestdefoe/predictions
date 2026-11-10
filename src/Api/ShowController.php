<?php

namespace ErnestDefoe\Predictions\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** GET /api/predictions/{id} */
class ShowController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);

        return new JsonResponse(['data' => $this->presenter->single(Find::visible($request, $actor), $actor)]);
    }
}
