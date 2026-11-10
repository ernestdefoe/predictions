<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Award;
use ErnestDefoe\Predictions\Score;
use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema\Attribute;
use Flarum\User\User;

/**
 * `predictionRecord` on a profile: this season and all time, and how many
 * times they were an Oracle.
 *
 * 🚨 Only on the profile's own request (GET /api/users/{id}), never on the
 * authors serialized with every discussion page: three small queries there,
 * none anywhere else.
 */
class UserFields
{
    /** @return list<Attribute> */
    public function __invoke(): array
    {
        return [
            Attribute::make('predictionRecord')
                ->visible(fn (User $user, Context $context) => $context->showing(UserResource::class) && $context->getActor()->hasPermission(Settings::VIEW))
                ->get(function (User $user) {
                    $current = Season::query()->whereNull('ended_at')->orderByDesc('id')->value('id');
                    $rows = Score::query()->where('user_id', $user->id)->get(['season_id', 'points', 'hits', 'settled', 'streak', 'best_streak']);
                    if ($rows->sum('settled') === 0) {
                        return null;
                    }
                    $season = $rows->firstWhere('season_id', $current);

                    return [
                        'season' => $season ? ['points' => $season->points, 'hits' => $season->hits, 'settled' => $season->settled, 'streak' => $season->streak] : null,
                        'points' => (int) $rows->sum('points'),
                        'hits' => (int) $rows->sum('hits'),
                        'settled' => (int) $rows->sum('settled'),
                        'bestStreak' => (int) $rows->max('best_streak'),
                        'oracle' => Award::query()->where('user_id', $user->id)->count(),
                    ];
                }),
        ];
    }
}
