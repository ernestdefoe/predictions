<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Award;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Seasons;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Tobyz\JsonApiServer\Context as BaseContext;

/**
 * The `prediction-seasons` type. Admins end the running season by creating the
 * next one: POST /api/prediction-seasons {name}. The ended season's top
 * predictors become its Oracles (Seasons::startNew). Seasons are listed with
 * the leaderboard, so there is no Index here.
 *
 * @extends AbstractDatabaseResource<Season>
 */
class SeasonResource extends AbstractDatabaseResource
{
    public function __construct(private readonly Seasons $seasons)
    {
    }

    public function type(): string
    {
        return 'prediction-seasons';
    }

    public function model(): string
    {
        return Season::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Create::make()->admin(),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')->requiredOnCreate()->writableOnCreate()->minLength(1)->maxLength(80),
            Schema\DateTime::make('startedAt')->property('started_at'),
            Schema\DateTime::make('endedAt')->property('ended_at')->nullable(),
            // How many Oracles the season that just ended named: only on the create response.
            Schema\Integer::make('oraclesNamed')
                ->visible(fn (Season $season, Context $context) => $context->creating(self::class))
                ->get(fn (Season $season) => Award::query()->where('season_id', Season::query()->where('id', '<', $season->id)->max('id'))->count()),
        ];
    }

    /** @param Context $context */
    public function create(object $model, BaseContext $context): object
    {
        return $this->seasons->startNew(trim((string) preg_replace('/\s+/u', ' ', $model->name)))['started'];
    }
}
