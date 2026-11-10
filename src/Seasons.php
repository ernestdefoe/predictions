<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Award\BadgeAward;
use Flarum\Extension\ExtensionManager;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Container\Container;

/**
 * Ending a season: the top predictors become its Oracles, the board starts
 * fresh. Predictions settled from then on count toward the new season.
 */
class Seasons
{
    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly ExtensionManager $extensions,
        private readonly Container $container,
    ) {
    }

    /** @return array{ended: Season, started: Season, oracles: list<int>} */
    public function startNew(string $name): array
    {
        [$ended, $started, $oracles] = Season::query()->getConnection()->transaction(function () use ($name) {
            $current = Season::query()->whereNull('ended_at')->orderByDesc('id')->lockForUpdate()->first() ?? Season::current();

            $top = Score::query()->where('season_id', $current->id)->where('points', '>', 0)
                ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
                ->limit(max(0, (int) ($this->settings->get(Settings::ORACLE_COUNT) ?? Settings::DEFAULT_ORACLE_COUNT)))
                ->get();

            foreach ($top->values() as $i => $score) {
                Award::query()->insert(['season_id' => $current->id, 'user_id' => $score->user_id, 'rank' => $i + 1, 'points' => $score->points]);
            }

            $current->ended_at = Carbon::now();
            $current->save();

            $next = new Season();
            $next->name = $name;
            $next->started_at = Carbon::now();
            $next->save();

            return [$current, $next, $top->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all()];
        });

        $this->award($ended, $oracles);

        return ['ended' => $ended, 'started' => $started, 'oracles' => $oracles];
    }

    /**
     * The Oracle group moves to this season's winners (only people WE put in
     * it leave it: anyone an admin added by hand stays). The FoF Badges badge
     * is kept for good, one more each season.
     *
     * @param list<int> $oracles
     */
    private function award(Season $season, array $oracles): void
    {
        $users = User::query()->whereIn('id', $oracles ?: [0])->get();

        $groupId = (int) $this->settings->get(Settings::ORACLE_GROUP);
        if ($groupId && ! in_array($groupId, [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID], true) && Group::query()->whereKey($groupId)->exists()) {
            $previous = Award::query()->where('season_id', '<>', $season->id)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
            $leaving = array_values(array_diff($previous, $oracles));
            if ($leaving) {
                Group::query()->find($groupId)?->users()->detach($leaving);
            }
            foreach ($users as $user) {
                $user->groups()->syncWithoutDetaching([$groupId]);
            }
        }

        if ($this->settings->get(Settings::ORACLE_BADGE) && $this->extensions->isEnabled('fof-badges') && class_exists(\FoF\Badges\BadgeAwarder::class)) {
            $this->container->make(BadgeAward::class)->award($season, $users->all());
        }
    }
}
