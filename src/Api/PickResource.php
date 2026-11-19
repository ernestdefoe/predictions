<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `prediction-picks` type: who picked what on one prediction, read-only.
 *
 * GET /api/prediction-picks?filter[prediction]=<id>   best results first, each with its member.
 *
 * scope() keeps every pick of a prediction the actor can't see, and every pick
 * of one whose creator keeps picks hidden until a lock that hasn't come yet.
 *
 * @extends AbstractDatabaseResource<Pick>
 */
class PickResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'prediction-picks';
    }

    public function model(): string
    {
        return Pick::class;
    }

    /** @param Builder<Pick> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        /** @var Context $context */
        $visible = Prediction::query()->visibleTo($context->getActor())
            ->where(fn (Builder $q) => $q->where('predictions.hide_picks', false)
                ->orWhere('predictions.lock_at', '<=', Carbon::now())
                ->orWhereNotNull('predictions.settled_at'))
            ->select('predictions.id');

        $query->whereIn('prediction_picks.prediction_id', $visible);
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->defaultInclude(['user'])
                ->paginate(100, 300)
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters): Context => $this->forPrediction($query, $pagination, $context, $filters)),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('optionId')->property('option_id')->nullable(),
            Schema\Number::make('value')->nullable(),
            Schema\Boolean::make('hit')->nullable(),
            Schema\Integer::make('points')->nullable(),
            Schema\DateTime::make('createdAt')->property('created_at'),
            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }

    /**
     * @param Builder<Pick> $query
     * @param array<string, mixed> $filters
     */
    private function forPrediction(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $query->where('prediction_picks.prediction_id', Input::id($filters['prediction'] ?? null) ?? 0)
            ->orderByDesc('prediction_picks.points')
            ->orderBy('prediction_picks.created_at')
            ->orderBy('prediction_picks.id');

        if ($pagination instanceof OffsetPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }
}
