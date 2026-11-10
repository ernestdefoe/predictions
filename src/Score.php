<?php

namespace ErnestDefoe\Predictions;

use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $season_id
 * @property int $user_id
 * @property int $points
 * @property int $hits
 * @property int $settled
 * @property int $streak
 * @property int $best_streak
 * @property-read User|null $user
 */
class Score extends AbstractModel
{
    protected $table = 'prediction_scores';

    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
