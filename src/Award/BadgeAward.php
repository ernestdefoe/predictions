<?php

namespace ErnestDefoe\Predictions\Award;

use ErnestDefoe\Predictions\Season;
use ErnestDefoe\Predictions\Settings;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\Badges\Badge;
use FoF\Badges\BadgeAwarder;
use FoF\Badges\UserBadge;
use Illuminate\Support\Str;

/**
 * The Oracle badge, through FoF Badges. Only ever constructed when FoF Badges
 * is enabled (Seasons checks), since every class named here is theirs.
 *
 * The badge is made the first time and remembered by id, so an admin can
 * rename or recolour it in FoF Badges. It has no trigger, so FoF Badges'
 * recalculation treats it as a manual badge and never takes it back.
 */
class BadgeAward
{
    public function __construct(
        private readonly BadgeAwarder $awarder,
        private readonly SettingsRepositoryInterface $settings,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /** @param list<User> $users */
    public function award(Season $season, array $users): void
    {
        if (! $users) {
            return;
        }

        $badge = $this->badge();
        foreach ($users as $user) {
            [$userBadge, $created] = $this->awarder->award($user, $badge, UserBadge::GRANTED_BY_API);
            if ($created) {
                $userBadge->reason = mb_substr((string) $this->translator->trans('ernestdefoe-predictions.lib.oracle_reason', ['season' => $season->name]), 0, 500);
                $userBadge->save();
            }
        }
    }

    private function badge(): Badge
    {
        $badge = Badge::query()->find((int) $this->settings->get(Settings::ORACLE_BADGE_ID));

        if (! $badge) {
            $name = (string) $this->translator->trans('ernestdefoe-predictions.lib.oracle');
            $slug = 'oracle';
            while (Badge::query()->where('slug', $slug)->exists()) {
                $slug = 'oracle-'.Str::lower(Str::random(4));
            }

            $badge = new Badge();
            $badge->name = $name;
            $badge->slug = $slug;
            $badge->description = (string) $this->translator->trans('ernestdefoe-predictions.lib.oracle_description');
            $badge->icon = 'fas fa-wand-magic-sparkles';
            $badge->icon_color = '#ffffff';
            $badge->background_color = '#6d28d9';
            $badge->is_active = true;
            $badge->is_visible = true;
            $badge->save();

            $this->settings->set(Settings::ORACLE_BADGE_ID, (string) $badge->id);
        }

        return $badge;
    }
}
