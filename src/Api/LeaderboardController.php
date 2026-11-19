<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Award;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/prediction-leaderboard?season=<id>|all&limit=50
 *
 * A computed report, not a resource: ranked rows (ties share a rank), the
 * viewer's own row and rank even when it's off the board, the seasons to pick
 * from and a finished season's Oracles. Records are predictions, picks and
 * seasons; this only reads their totals, so it stays a plain JSON route.
 *
 * 🚨 No N+1: the board is one query on prediction_scores (kept up to date by
 * the scorer, never summed from picks here) plus one for its members, one for
 * the seasons, one for the viewer's own rank and, for a finished season, one
 * for its Oracles. The same five queries for 5 rows or 50.
 */
class LeaderboardController implements RequestHandlerInterface
{
    /** All-time totals add up each member's season rows; there is no column to read them from. */
    private const TOTALS = 'user_id, SUM(points) as points, SUM(hits) as hits, SUM(settled) as settled, MAX(best_streak) as best_streak';

    public function __construct(private readonly Presenter $presenter)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan(Settings::VIEW);

        $params = $request->getQueryParams();
        $limit = min(100, max(1, (int) Arr::get($params, 'limit', 50)));
        $seasons = Season::query()->orderByDesc('id')->get();
        $current = $seasons->firstWhere('ended_at', null) ?? Season::current();
        $wanted = Arr::get($params, 'season');
        $season = $wanted === 'all' ? null : ($seasons->firstWhere('id', (int) $wanted) ?? $current);

        $rows = $season ? $this->season($season, $limit) : $this->allTime($limit);
        $oracles = $season?->ended_at ? Award::query()->where('season_id', $season->id)->orderBy('rank')->get() : collect();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->merge($oracles->pluck('user_id'))->unique()->values()->all())->get()->keyBy('id');

        return new JsonResponse([
            'season' => $season?->toRow(),
            'currentSeasonId' => $current->id,
            'seasons' => $seasons->map(fn (Season $s) => $s->toRow())->values()->all(),
            'rows' => $this->ranked($rows, $users),
            'me' => $actor->isGuest() ? null : $this->me($actor, $season),
            'oracles' => $oracles->filter(fn (Award $a) => $users->has($a->user_id))
                ->map(fn (Award $a) => ['rank' => $a->rank, 'points' => $a->points, 'user' => $this->presenter->user($users->get($a->user_id))])
                ->values()->all(),
        ]);
    }

    /** @return Collection<int, Score> */
    private function season(Season $season, int $limit): Collection
    {
        return Score::query()
            ->where('season_id', $season->id)->where('settled', '>', 0)
            ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
            ->limit($limit)->get(['user_id', 'points', 'hits', 'settled', 'streak', 'best_streak']);
    }

    /** @return Collection<int, Score> */
    private function allTime(int $limit): Collection
    {
        return $this->totals(Score::query())
            ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
            ->limit($limit)->get();
    }

    /**
     * Season rows summed per member, with only members who have a settled pick.
     * SUM/HAVING have no builder method, so these aggregates over our own columns
     * are raw; nothing from the request goes into them except a bound value.
     *
     * @param Builder<Score> $query
     * @return Builder<Score>
     */
    private function totals(Builder $query): Builder
    {
        return $query->selectRaw(self::TOTALS)->groupBy('user_id')->havingRaw('SUM(settled) > 0');
    }

    /**
     * Competition ranking: members with the same points share a rank (1, 2, 2, 4).
     *
     * @param Collection<int, Score> $rows
     * @param Collection<int, User> $users
     * @return list<array<string, mixed>>
     */
    private function ranked(Collection $rows, Collection $users): array
    {
        $board = [];
        $rank = 0;
        $last = null;
        foreach ($rows->values() as $i => $row) {
            if ($last === null || (int) $row->points !== $last) {
                $rank = $i + 1;
                $last = (int) $row->points;
            }
            if ($user = $users->get($row->user_id)) {
                $board[] = $this->row($row, $user, $rank);
            }
        }

        return $board;
    }

    /** @return array<string, mixed>|null */
    private function me(User $actor, ?Season $season): ?array
    {
        if ($season) {
            $mine = Score::query()->where('season_id', $season->id)->where('user_id', $actor->id)->where('settled', '>', 0)->first();
            $better = $mine ? Score::query()->where('season_id', $season->id)->where('settled', '>', 0)->where('points', '>', $mine->points)->count() : 0;
        } else {
            $mine = $this->totals(Score::query()->where('user_id', $actor->id))->first();
            $better = $mine ? Score::query()->getConnection()->query()->fromSub(
                $this->totals(Score::query())->havingRaw('SUM(points) > ?', [(int) $mine->points]),
                'better'
            )->count() : 0;
        }

        return $mine ? $this->row($mine, $actor, $better + 1) : null;
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
