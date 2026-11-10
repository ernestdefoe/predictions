<?php

namespace ErnestDefoe\Predictions;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $season_id
 * @property int $user_id
 * @property int $rank
 * @property int $points
 */
class Award extends AbstractModel
{
    protected $table = 'prediction_awards';

    public $timestamps = false;
}
