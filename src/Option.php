<?php

namespace ErnestDefoe\Predictions;

use Flarum\Database\AbstractModel;

/**
 * @property int $id
 * @property int $prediction_id
 * @property string $label
 * @property int $position
 * @property int $picks_count
 */
class Option extends AbstractModel
{
    protected $table = 'prediction_options';

    public $timestamps = false;
}
