<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Settings;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/predictions
 *   filter[status]=open|locked|settled   open: soonest lock first; locked: waiting on a result; settled: newest first
 *   filter[id]=1,2,3                     the cards a page's embedded links name (at most 50)
 *   filter[discussion]=12                the ones attached to a discussion
 *   filter[mine]=picked|created          the viewer's
 *   filter[user]=7                       one member's picks (their profile)
 *   page[offset], page[limit] (≤ 50)
 */
class ListController extends Handler
{
    public const LIMIT = 20;

    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $filter = (array) Arr::get($params, 'filter', []);
        $offset = max(0, (int) Arr::get($params, 'page.offset', 0));
        $limit = min(50, max(1, (int) Arr::get($params, 'page.limit', self::LIMIT)));
        $now = Carbon::now();

        $query = Prediction::query()->visibleTo($actor);

        if (isset($filter['id'])) {
            $ids = array_slice(array_filter(array_map('intval', explode(',', (string) $filter['id']))), 0, 50);
            $query->whereIn('predictions.id', $ids ?: [0]);
            $limit = 50;
            $offset = 0;
        }
        if (isset($filter['discussion'])) {
            $query->where('predictions.discussion_id', (int) $filter['discussion']);
        }

        $mine = $filter['mine'] ?? null;
        $user = Input::id($filter['user'] ?? null);
        if ($mine === 'created') {
            $query->where('predictions.user_id', $actor->isGuest() ? 0 : $actor->id);
        } elseif ($mine === 'picked' || $user) {
            $query->whereIn('predictions.id', Pick::query()->where('user_id', $user ?? ($actor->isGuest() ? 0 : $actor->id))->select('prediction_id'));
            if ($user && $user !== $actor->id) {
                // Someone else's: only where their picks are already out in the open.
                $query->where(fn ($q) => $q->where('predictions.hide_picks', false)->orWhere('predictions.lock_at', '<=', $now));
            }
        }

        switch ($filter['status'] ?? null) {
            case 'open':
                $query->whereNull('predictions.settled_at')->where('predictions.lock_at', '>', $now)->orderBy('predictions.lock_at')->orderBy('predictions.id');
                break;
            case 'locked':
                $query->whereNull('predictions.settled_at')->where('predictions.lock_at', '<=', $now)->orderByDesc('predictions.lock_at')->orderByDesc('predictions.id');
                break;
            case 'settled':
                $query->whereNotNull('predictions.settled_at')->orderByDesc('predictions.settled_at')->orderByDesc('predictions.id');
                break;
            default:
                $query->orderByDesc('predictions.created_at')->orderByDesc('predictions.id');
        }

        // One past the page tells us whether there are more, without counting them all.
        $rows = $query->skip($offset)->take($limit + 1)->get();
        $more = $rows->count() > $limit;

        return new JsonResponse([
            'data' => $this->presenter->many($rows->take($limit), $actor),
            'more' => $more,
            'meta' => ['canCreate' => $actor->hasPermission(Settings::CREATE) && ! $actor->isGuest()],
        ]);
    }
}
