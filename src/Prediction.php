<?php

namespace ErnestDefoe\Predictions;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $discussion_id
 * @property int|null $season_id
 * @property string $type
 * @property string $title
 * @property string|null $description
 * @property string|null $unit
 * @property float|null $tolerance
 * @property bool $hide_picks
 * @property Carbon $lock_at
 * @property Carbon|null $locked_at
 * @property int $picks_count
 * @property array<string, mixed>|null $stats
 * @property int|null $correct_option_id
 * @property float|null $answer_value
 * @property Carbon|null $settled_at
 * @property int|null $settled_by_id
 * @property Carbon|null $voided_at
 * @property Carbon|null $scored_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User|null $user
 * @property-read Discussion|null $discussion
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Option> $options
 */
class Prediction extends AbstractModel
{
    public const CHOICE = 'choice';
    public const YESNO = 'yesno';
    public const NUMBER = 'number';
    public const TYPES = [self::CHOICE, self::YESNO, self::NUMBER];

    protected $table = 'predictions';

    public $timestamps = true;

    protected $casts = [
        'user_id' => 'integer',
        'discussion_id' => 'integer',
        'season_id' => 'integer',
        'tolerance' => 'float',
        'hide_picks' => 'boolean',
        'lock_at' => 'datetime',
        'locked_at' => 'datetime',
        'picks_count' => 'integer',
        'stats' => 'array',
        'correct_option_id' => 'integer',
        'answer_value' => 'float',
        'settled_at' => 'datetime',
        'voided_at' => 'datetime',
        'scored_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Discussion, $this> */
    public function discussion(): BelongsTo
    {
        return $this->belongsTo(Discussion::class);
    }

    /** @return HasMany<Option, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Pick, $this> */
    public function picks(): HasMany
    {
        return $this->hasMany(Pick::class);
    }

    /** Locked by the clock, whether or not the scheduler has stamped it yet: picks are judged by this. */
    public function isLocked(): bool
    {
        return $this->lock_at->lte(Carbon::now());
    }

    public function isSettled(): bool
    {
        return $this->settled_at !== null;
    }

    public function status(): string
    {
        return match (true) {
            $this->voided_at !== null => 'void',
            $this->settled_at !== null => 'settled',
            $this->isLocked() => 'locked',
            default => 'open',
        };
    }

    /** Counts and other people's picks: always after the lock, before it only when the creator allowed it. */
    public function picksVisible(): bool
    {
        return ! $this->hide_picks || $this->isLocked() || $this->isSettled();
    }

    public function canModerate(User $actor): bool
    {
        return $actor->hasPermission(Settings::MODERATE);
    }

    public function isOwner(User $actor): bool
    {
        return ! $actor->isGuest() && $this->user_id === $actor->id;
    }

    public function canSettle(User $actor): bool
    {
        return $this->isOwner($actor) || $this->canModerate($actor);
    }

    /** Wording and lock time: the creator while it's open, a moderator until it's settled. */
    public function canEdit(User $actor): bool
    {
        return ($this->isOwner($actor) && ! $this->isLocked() && ! $this->isSettled())
            || ($this->canModerate($actor) && ! $this->isSettled());
    }

    /** The choices themselves: only before anyone else has picked, so nobody's pick changes under them. */
    public function canEditOptions(User $actor, int $othersPicks): bool
    {
        return $this->canEdit($actor) && $othersPicks === 0;
    }

    public function canDelete(User $actor, int $othersPicks): bool
    {
        return $this->canModerate($actor) || ($this->isOwner($actor) && $othersPicks === 0);
    }

    /**
     * What the actor may see: the view permission, and the discussion's
     * visibility for one attached to a discussion.
     *
     * @param Builder<Prediction> $query
     */
    public function scopeVisibleTo(Builder $query, User $actor): void
    {
        if (! $actor->hasPermission(Settings::VIEW)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            $q->whereNull('predictions.discussion_id')
                ->orWhereIn('predictions.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
        });
    }
}
