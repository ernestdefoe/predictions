<?php

namespace ErnestDefoe\Predictions\Award;

use ErnestDefoe\Predictions\Season;

/** Without FoF Badges there is no badge to give: the Oracle group (if set) is the award. */
class NoOracleBadge implements OracleBadge
{
    public function award(Season $season, array $users): void
    {
    }
}
