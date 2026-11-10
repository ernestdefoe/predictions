<?php

namespace ErnestDefoe\Predictions;

final class Settings
{
    public const PREFIX = 'ernestdefoe-predictions.';

    public const NAV = self::PREFIX.'nav';
    public const BOLD_BONUS = self::PREFIX.'bold_bonus';
    public const ORACLE_COUNT = self::PREFIX.'oracle_count';
    public const ORACLE_GROUP = self::PREFIX.'oracle_group';
    public const ORACLE_BADGE = self::PREFIX.'oracle_badge';
    /** Set by the first award: the FoF Badges badge we made, so later seasons reuse it. */
    public const ORACLE_BADGE_ID = self::PREFIX.'oracle_badge_id';

    public const VIEW = 'predictions.view';
    public const CREATE = 'predictions.create';
    public const PICK = 'predictions.pick';
    public const MODERATE = 'predictions.moderate';

    public const DEFAULT_ORACLE_COUNT = 3;
}
