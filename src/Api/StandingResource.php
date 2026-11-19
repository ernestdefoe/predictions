<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Award;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Scores;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Closure;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\OffsetPagination;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `prediction-standings` type: the leaderboard, read-only.
 *
 * GET /api/prediction-standings?filter[season]=<id>|all[&filter[user]=<id>]
 *
 * One row per member with a settled pick, best first, each with its rank (members on the
 * same points share it: 1, 2, 2, 4), whether they were named the season's Oracle, and the
 * member. filter[user] gives one member's own row and rank, even when it's off the board.
 * meta carries the season shown, the running one and the seasons to pick from.
 *
 * 🚨 No N+1: rows are one query on prediction_scores (kept by the scorer, never summed from
 * picks here), plus the members, the seasons, one count for the first row's rank and, for a
 * finished season, one for its Oracles. The same for 5 rows or 50. All-time totals add up
 * every season row, so their rows, counts and ranks are cached until a season row changes
 * (`Scores::version()`, bumped on every rebuild).
 *
 * @extends AbstractDatabaseResource<Score>
 */
class StandingResource extends AbstractDatabaseResource
{
    /** All-time totals add up each member's season rows; there is no column to read them from. */
    private const TOTALS = 'user_id, SUM(points) as points, SUM(hits) as hits, SUM(settled) as settled, MAX(best_streak) as best_streak';

    /** How long a cached all-time answer lives, at most (a rebuild replaces it sooner). */
    private const CACHE_SECONDS = 3600;

    public function __construct(
        private readonly Repository $cache,
        private readonly Scores $scores,
    ) {
    }

    /**
     * @param Builder<Score> $query
     * @return iterable<Score>
     */
    public function results(object $query, BaseContext $context): iterable
    {
        if (! $this->allTime($context)) {
            return parent::results($query, $context);
        }

        return Score::hydrate($this->cached($query, 'rows', fn () => $query->toBase()->get()->map(fn ($row) => (array) $row)->all()));
    }

    /** @param Builder<Score> $query */
    public function count(object $query, BaseContext $context): ?int
    {
        if (! $this->allTime($context)) {
            return parent::count($query, $context);
        }

        return $this->cached($query, 'count', fn () => $query->toBase()->getCountForPagination());
    }

    private function allTime(BaseContext $context): bool
    {
        return $context instanceof Context && $context->internal('season') === null;
    }

    /**
     * An all-time answer, cached under the scores' version and this exact query.
     *
     * @template T
     * @param Builder<Score>|\Illuminate\Database\Query\Builder $query
     * @param Closure(): T $work
     * @return T
     */
    private function cached(object $query, string $what, Closure $work): mixed
    {
        $key = 'ernestdefoe-predictions.all-time.'.$this->scores->version().'.'.$what.'.'.md5($query->toSql().'|'.json_encode($query->getBindings()));

        return $this->cache->remember($key, self::CACHE_SECONDS, $work);
    }

    public function type(): string
    {
        return 'prediction-standings';
    }

    public function model(): string
    {
        return Score::class;
    }

    /** One member's row in one season ("3-12"), or across all of them ("all-12"). */
    public function getId(object $model, BaseContext $context): string
    {
        return ($model->season_id ?? 'all').'-'.$model->user_id;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->can(Settings::VIEW)
                ->defaultInclude(['user'])
                ->paginate(50, 100)
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters): Context => $this->board($query, $pagination, $context, $filters))
                ->after(fn (Context $context, iterable $rows) => $this->rank($context, $rows))
                ->meta([
                    Schema\Arr::make('season')->nullable()->get(fn (Context $context) => $context->internal('season')?->toRow()),
                    Schema\Integer::make('currentSeasonId')->nullable()->get(fn (Context $context) => $context->internal('current')?->id),
                    Schema\Arr::make('seasons')->get(fn (Context $context) => $context->internal('seasons')->map(fn (Season $s) => $s->toRow())->values()->all()),
                ]),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Integer::make('rank'),
            Schema\Integer::make('points')->get(fn (Score $row) => (int) $row->points),
            Schema\Integer::make('hits')->get(fn (Score $row) => (int) $row->hits),
            Schema\Integer::make('settled')->get(fn (Score $row) => (int) $row->settled),
            Schema\Number::make('hitRate')->get(fn (Score $row) => $row->settled ? round((int) $row->hits / (int) $row->settled, 4) : 0),
            // A season's current run of hits; all-time totals have none.
            Schema\Integer::make('streak')->nullable()->get(fn (Score $row) => $row->season_id === null ? null : (int) $row->streak),
            Schema\Integer::make('bestStreak')->get(fn (Score $row) => (int) $row->best_streak),
            Schema\Boolean::make('oracle')->get(fn (Score $row) => (bool) $row->getAttribute('oracle')),
            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }

    /**
     * @param Builder<Score> $query
     * @param array<string, mixed> $filters
     */
    private function board(Builder $query, ?Pagination $pagination, Context $context, array $filters): Context
    {
        $seasons = Season::query()->orderByDesc('id')->get();
        // Read only: a GET never opens a season (Season::current() would, if none were open).
        $current = $seasons->firstWhere('ended_at', null) ?? $seasons->first();
        $wanted = $filters['season'] ?? null;
        $season = $wanted === 'all' ? null : ($seasons->firstWhere('id', Input::id($wanted)) ?? $current);

        $this->rows($query, $season);
        $offset = 0;
        if (($user = Input::id($filters['user'] ?? null)) !== null) {
            $query->where('user_id', $user);
        } elseif ($pagination instanceof OffsetPagination) {
            $pagination->apply($query);
            $offset = $pagination->offset;
        }

        return $context->withQuery($query)
            ->withInternal('offset', $offset)
            ->withInternal('seasons', $seasons)
            ->withInternal('current', $current)
            ->withInternal('season', $season);
    }

    /** @param Builder<Score> $query */
    private function rows(Builder $query, ?Season $season): void
    {
        if ($season) {
            $query->where('season_id', $season->id)->where('settled', '>', 0)
                ->select(['season_id', 'user_id', 'points', 'hits', 'settled', 'streak', 'best_streak']);
        } else {
            $this->totals($query);
        }
        $query->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id');
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
     * The members and competition ranks. The first row's rank is one more than the members with more
     * points (one count); after it, rows are in points order, so a row with fewer points
     * than the one before is ranked by its place on the whole board (offset + position).
     *
     * @param iterable<Score> $rows
     * @return iterable<Score>
     */
    private function rank(Context $context, iterable $rows): iterable
    {
        $rows = EloquentCollection::make($rows)->values();
        if ($rows->isEmpty()) {
            return $rows;
        }
        // The members, in one query, as visible to the viewer. Loaded here rather than by the
        // serializer, whose loader keys rows by primary key and all-time totals have none.
        $rows->load(['user' => fn ($query) => $query->whereVisibleTo($context->getActor())]);

        $season = $context->internal('season');
        $first = $rows->first();
        $better = $this->better($season, (int) $first->points);
        $oracles = $season?->ended_at ? Award::query()->where('season_id', $season->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all() : [];

        $offset = (int) $context->internal('offset', 0);
        $rank = $better + 1;
        foreach ($rows as $i => $row) {
            if ($i > 0 && (int) $row->points !== (int) $rows[$i - 1]->points) {
                $rank = $offset + $i + 1;
            }
            $row->setAttribute('rank', $rank);
            $row->setAttribute('oracle', in_array((int) $row->user_id, $oracles, true));
        }

        return $rows;
    }

    /** How many members have more points than this in the season (or all time). */
    private function better(?Season $season, int $points): int
    {
        if ($season) {
            return Score::query()->where('season_id', $season->id)->where('settled', '>', 0)->where('points', '>', $points)->count();
        }

        $better = Score::query()->getConnection()->query()
            ->fromSub($this->totals(Score::query())->havingRaw('SUM(points) > ?', [$points]), 'better');

        return $this->cached($better, 'better', fn () => $better->count());
    }
}
