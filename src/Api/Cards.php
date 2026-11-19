<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Prediction;
use Flarum\Api\Context;
use Psr\Http\Message\ServerRequestInterface;
use WeakMap;

/**
 * What PredictionResource's attributes read: every prediction serialized in one
 * request, laid out by Presenter::many in one batch.
 *
 * 🚨 No N+1: a field getter queues its prediction and returns a closure, which
 * Flarum resolves after every other getter has run; the first closure lays out
 * all the queued predictions at once (options, the viewer's picks, the
 * "closest" faces and ended seasons are one query each for the whole page).
 * State is keyed by the request in a WeakMap, never kept on a model.
 */
class Cards
{
    /** @var WeakMap<ServerRequestInterface, array{queued: array<int, Prediction>, rows: array<int, array<string, mixed>>}> */
    private WeakMap $byRequest;

    public function __construct(private readonly Presenter $presenter)
    {
        $this->byRequest = new WeakMap();
    }

    public function value(Prediction $prediction, Context $context, string $key): \Closure
    {
        $request = $context->request;
        ['queued' => $queued, 'rows' => $rows] = $this->byRequest[$request] ?? ['queued' => [], 'rows' => []];
        $queued[$prediction->id] = $prediction;
        $this->byRequest[$request] = ['queued' => $queued, 'rows' => $rows];

        return function () use ($request, $prediction, $context, $key) {
            ['queued' => $queued, 'rows' => $rows] = $this->byRequest[$request];
            if (! isset($rows[$prediction->id])) {
                $pending = array_diff_key($queued, $rows);
                foreach ($this->presenter->many(collect(array_values($pending)), $context->getActor()) as $row) {
                    $rows[(int) $row['id']] = $row;
                }
                $this->byRequest[$request] = ['queued' => $queued, 'rows' => $rows];
            }

            return $rows[$prediction->id][$key] ?? null;
        };
    }
}
