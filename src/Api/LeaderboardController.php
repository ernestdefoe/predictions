<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Award;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/prediction-leaderboard?season=<id>|all&limit=50
 *
 * 🚨 No N+1: the board is one query on prediction_scores (kept up to date by
 * the scorer, never summed from picks here) plus one for its members, one for
 * the seasons, one for the viewer's own rank and, for a finished season, one
 * for its Oracles. The same five queries for 5 rows or 50.
 */
class LeaderboardController extends Handler
{
    public function __construct(private readonly Presenter $presenter)
    {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) Arr::get($params, 'limit', 50)));
        $seasons = Season::query()->orderByDesc('id')->get();
        $current = $seasons->firstWhere('ended_at', null) ?? Season::current();
        $wanted = Arr::get($params, 'season');
        $allTime = $wanted === 'all';
        $season = $allTime ? null : ($seasons->firstWhere('id', (int) $wanted) ?? $current);

        $rows = $allTime ? $this->allTime($limit) : Score::query()
            ->where('season_id', $season->id)->where('settled', '>', 0)
            ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
            ->limit($limit)->get(['user_id', 'points', 'hits', 'settled', 'streak', 'best_streak']);

        $ids = $rows->pluck('user_id');
        $oracles = $season && $season->ended_at ? Award::query()->where('season_id', $season->id)->orderBy('rank')->get() : collect();
        $users = User::query()->whereIn('id', $ids->merge($oracles->pluck('user_id'))->unique()->values()->all() ?: [0])->get()->keyBy('id');

        $board = [];
        $rank = 0;
        $last = null;
        foreach ($rows->values() as $i => $row) {
            $user = $users->get($row->user_id);
            if ($last === null || (int) $row->points !== $last) {
                $rank = $i + 1;
                $last = (int) $row->points;
            }
            if ($user) {
                $board[] = $this->row($row, $user, $rank);
            }
        }

        return new JsonResponse([
            'season' => $season?->toRow(),
            'currentSeasonId' => $current->id,
            'seasons' => $seasons->map(fn (Season $s) => $s->toRow())->values()->all(),
            'rows' => $board,
            'me' => $actor->isGuest() ? null : $this->me($actor, $season),
            'oracles' => $oracles->map(fn (Award $a) => $users->has($a->user_id) ? ['rank' => $a->rank, 'points' => $a->points, 'user' => $this->presenter->user($users->get($a->user_id))] : null)->filter()->values()->all(),
        ]);
    }

    /** @return Collection<int, Score> */
    private function allTime(int $limit): Collection
    {
        return Score::query()
            ->selectRaw('user_id, SUM(points) as points, SUM(hits) as hits, SUM(settled) as settled, MAX(best_streak) as best_streak')
            ->groupBy('user_id')->havingRaw('SUM(settled) > 0')
            ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
            ->limit($limit)->get();
    }

    /** @return array<string, mixed>|null */
    private function me(User $actor, ?Season $season): ?array
    {
        if ($season) {
            $mine = Score::query()->where('season_id', $season->id)->where('user_id', $actor->id)->where('settled', '>', 0)->first();
            if (! $mine) {
                return null;
            }
            $better = Score::query()->where('season_id', $season->id)->where('settled', '>', 0)->where('points', '>', $mine->points)->count();
        } else {
            $mine = Score::query()->where('user_id', $actor->id)
                ->selectRaw('user_id, SUM(points) as points, SUM(hits) as hits, SUM(settled) as settled, MAX(best_streak) as best_streak')
                ->groupBy('user_id')->havingRaw('SUM(settled) > 0')->first();
            if (! $mine) {
                return null;
            }
            $better = Score::query()->getConnection()->query()->fromSub(
                Score::query()->select('user_id')->groupBy('user_id')->havingRaw('SUM(settled) > 0')->havingRaw('SUM(points) > ?', [(int) $mine->points]),
                'better'
            )->count();
        }

        return $this->row($mine, $actor, $better + 1);
    }

    /** @return array<string, mixed> */
    private function row(Score $row, User $user, int $rank): array
    {
        $settled = (int) $row->settled;

        return [
            'rank' => $rank,
            'user' => $this->presenter->user($user),
            'points' => (int) $row->points,
            'hits' => (int) $row->hits,
            'settled' => $settled,
            'hitRate' => $settled ? round((int) $row->hits / $settled, 4) : 0,
            'streak' => isset($row->streak) ? (int) $row->streak : null,
            'bestStreak' => (int) $row->best_streak,
        ];
    }
}
