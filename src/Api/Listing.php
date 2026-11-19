<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use Flarum\Api\Context;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * GET /api/predictions filters (PredictionResource's Index; scope() has already applied visibility):
 *   filter[status]=open|locked|settled   open: soonest lock first; locked: waiting on a result; settled: newest first
 *   filter[id]=1,2,3                     the cards a page's embedded links name (at most 50)
 *   filter[discussion]=12                the ones attached to a discussion
 *   filter[mine]=picked|created          the viewer's
 *   filter[user]=7                       one member's picks (their profile)
 */
class Listing
{
    /**
     * @param Builder<Prediction> $query
     * @param array<string, mixed> $filters
     */
    public function apply(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $actor = $context->getActor();

        if (isset($filters['id'])) {
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $filters['id']))), 0, 50);
            $query->whereIn('predictions.id', $ids);
        }
        if (isset($filters['discussion'])) {
            $query->where('predictions.discussion_id', (int) $filters['discussion']);
        }
        $this->whose($query, $actor, $filters);
        $this->status($query, $filters['status'] ?? null);

        if ($pagination instanceof OffsetPagination) {
            $pagination->apply($query);
        }

        return $context->withQuery($query);
    }

    /**
     * @param Builder<Prediction> $query
     * @param array<string, mixed> $filters
     */
    private function whose(Builder $query, User $actor, array $filters): void
    {
        $mine = $filters['mine'] ?? null;
        $user = Input::id($filters['user'] ?? null);
        // A guest has no picks or predictions of their own (0 matches nobody).
        $me = $actor->isGuest() ? 0 : (int) $actor->id;

        if ($mine === 'created') {
            $query->where('predictions.user_id', $me);
        } elseif ($mine === 'picked' || $user) {
            $query->whereIn('predictions.id', Pick::query()->where('user_id', $user ?? $me)->select('prediction_id'));
            if ($user && $user !== $me) {
                // Someone else's: only where their picks are already out in the open.
                $query->where(fn (Builder $q) => $q->where('predictions.hide_picks', false)->orWhere('predictions.lock_at', '<=', Carbon::now()));
            }
        }
    }

    /** @param Builder<Prediction> $query */
    private function status(Builder $query, mixed $status): void
    {
        $now = Carbon::now();

        match ($status) {
            'open' => $query->whereNull('predictions.settled_at')->where('predictions.lock_at', '>', $now)->orderBy('predictions.lock_at')->orderBy('predictions.id'),
            'locked' => $query->whereNull('predictions.settled_at')->where('predictions.lock_at', '<=', $now)->orderByDesc('predictions.lock_at')->orderByDesc('predictions.id'),
            'settled' => $query->whereNotNull('predictions.settled_at')->orderByDesc('predictions.settled_at')->orderByDesc('predictions.id'),
            default => $query->orderByDesc('predictions.created_at')->orderByDesc('predictions.id'),
        };
    }
}
