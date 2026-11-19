<?php

namespace ErnestDefoe\Predictions;

final class Settings
{
    public const PREFIX = 'ernestdefoe-predictions.';

    public const BOLD_BONUS = self::PREFIX.'bold_bonus';
    public const ORACLE_COUNT = self::PREFIX.'oracle_count';
    public const ORACLE_GROUP = self::PREFIX.'oracle_group';
    public const ORACLE_BADGE = self::PREFIX.'oracle_badge';
    /** Set by the first award: the FoF Badges badge we made, so later seasons reuse it. */
    public const ORACLE_BADGE_ID = self::PREFIX.'oracle_badge_id';
    /** Pickers (besides whoever settles it) a prediction needs to count on the leaderboard. */
    public const MIN_PICKERS = self::PREFIX.'min_pickers';
    /** Predictions a member who isn't a moderator may open in 24 hours. */
    public const DAILY_LIMIT = self::PREFIX.'daily_limit';

    public const VIEW = 'predictions.view';
    public const CREATE = 'predictions.create';
    public const PICK = 'predictions.pick';
    public const MODERATE = 'predictions.moderate';
}
