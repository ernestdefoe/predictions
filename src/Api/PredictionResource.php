<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Prediction;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Http\RequestUtil;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `predictions` JSON:API type, with no endpoints of its own: the forum
 * reads predictions through the plain-JSON controllers. It exists because a
 * notification's subject must have a registered resource, or every
 * notification list 500s.
 *
 * @extends AbstractDatabaseResource<Prediction>
 */
class PredictionResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'predictions';
    }

    public function model(): string
    {
        return Prediction::class;
    }

    public function scope(Builder $query, BaseContext $context): void
    {
        /** @var Builder<Prediction> $query */
        $query->visibleTo(RequestUtil::getActor($context->request));
    }

    public function endpoints(): array
    {
        return [];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('title'),
            Schema\Str::make('status')->get(fn (Prediction $p) => $p->status()),
        ];
    }
}
