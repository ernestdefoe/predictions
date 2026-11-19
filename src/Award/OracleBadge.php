<?php

namespace ErnestDefoe\Predictions\Award;

use ErnestDefoe\Predictions\Season;
use Flarum\User\User;

/**
 * Gives a season's Oracles their badge. Bound to NoOracleBadge, and to
 * BadgeAward when FoF Badges is enabled (Extend\Conditional in extend.php).
 */
interface OracleBadge
{
    /** @param list<User> $users */
    public function award(Season $season, array $users): void;
}
