<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $prediction_id
 * @property int $user_id
 * @property int|null $option_id
 * @property float|null $value
 * @property bool|null $hit
 * @property int|null $points
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 */
class Pick extends AbstractModel
{
    protected $table = 'prediction_picks';

    public $timestamps = true;

    protected $casts = [
        'option_id' => 'integer',
        'value' => 'float',
        'hit' => 'boolean',
        'points' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{optionId: int|null, value: float|null, hit: bool|null, points: int|null} */
    public function toRow(): array
    {
        return ['optionId' => $this->option_id, 'value' => $this->value, 'hit' => $this->hit, 'points' => $this->points];
    }
}
