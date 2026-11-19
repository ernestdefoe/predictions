<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Award\OracleBadge;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Event\GroupsChanged;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Ending a season: the top predictors become its Oracles, the board starts
 * fresh. Predictions settled from then on count toward the new season.
 */
class Seasons
{
    /** The most Oracles a season names (the admin field's limit, held here too). */
    public const MAX_ORACLES = 25;

    public const NEVER_ORACLE_GROUPS = [Group::ADMINISTRATOR_ID, Group::GUEST_ID, Group::MEMBER_ID, Group::MODERATOR_ID];

    public function __construct(
        private readonly SettingsRepositoryInterface $settings,
        private readonly OracleBadge $badge,
        private readonly RowLock $lock,
        private readonly Dispatcher $events,
    ) {
    }

    /** @return array{ended: Season, started: Season, oracles: list<int>} */
    public function startNew(string $name): array
    {
        // One season change at a time: two admins clicking at once must not end two seasons.
        [$ended, $started, $oracles] = $this->lock->run('season', function () use ($name) {
            $current = Season::query()->whereNull('ended_at')->orderByDesc('id')->lockForUpdate()->first() ?? Season::current();

            $top = Score::query()->where('season_id', $current->id)->where('points', '>', 0)
                ->orderByDesc('points')->orderByDesc('hits')->orderBy('settled')->orderBy('user_id')
                ->limit(min(self::MAX_ORACLES, max(0, (int) $this->settings->get(Settings::ORACLE_COUNT))))
                ->get();

            if ($top->isNotEmpty()) {
                Award::query()->insert($top->values()->map(fn (Score $score, int $i) => [
                    'season_id' => $current->id, 'user_id' => $score->user_id, 'rank' => $i + 1, 'points' => $score->points,
                ])->all());
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
     * The Oracle group moves to this season's winners. Only memberships this
     * extension added are ever taken back (each award row records the group it
     * put the member in): someone an admin put in the group by hand stays. Admins,
     * Moderators, Members and Guests are never an Oracle group. The badge (FoF
     * Badges) goes through OracleBadge, which does nothing without FoF Badges.
     *
     * @param list<int> $oracles
     */
    private function award(Season $season, array $oracles): void
    {
        // Stored as "g5" by the admin select (letters keep its options in order); a bare "5" works too.
        $groupId = (int) preg_replace('/\D+/', '', (string) $this->settings->get(Settings::ORACLE_GROUP));
        $group = in_array($groupId, self::NEVER_ORACLE_GROUPS, true) ? null : Group::query()->find($groupId);
        if ($group) {
            $this->moveGroup($group, $season, $oracles);
        }

        $this->badge->award($season, User::query()->whereIn('id', $oracles ?: [0])->get()->all());
    }

    /** @param list<int> $oracles */
    private function moveGroup(Group $group, Season $season, array $oracles): void
    {
        // Memberships we gave in earlier seasons.
        $ours = Award::query()->where('season_id', '<>', $season->id)->where('group_id', $group->id)->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->all();
        $leaving = array_values(array_diff($ours, $oracles));
        $members = $group->users()->whereIn('users.id', $oracles ?: [0])->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $joining = array_values(array_diff($oracles, $members));

        // Their groups as they were, for GroupsChanged (one query for everyone who moves).
        $moving = User::query()->whereIn('id', [...$leaving, ...$joining] ?: [0])->with('groups')->get();
        $before = $moving->mapWithKeys(fn (User $user) => [$user->id => $user->groups->all()]);

        if ($leaving) {
            $group->users()->detach($leaving);
            Award::query()->where('group_id', $group->id)->whereIn('user_id', $leaving)->update(['group_id' => null]);
        }
        if ($joining) {
            $group->users()->attach($joining);
        }
        // Ours if we add them now, or we added them before and they stay; not if an admin did.
        $recorded = array_values(array_unique([...$joining, ...array_intersect($oracles, $ours)]));
        if ($recorded) {
            Award::query()->where('season_id', $season->id)->whereIn('user_id', $recorded)->update(['group_id' => $group->id]);
        }

        // Other extensions (and core's caches) hear about every membership this changed.
        foreach ($moving as $user) {
            $user->unsetRelation('groups');
            $this->events->dispatch(new GroupsChanged($user, $before[$user->id]));
        }
    }
}
