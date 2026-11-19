<?php

namespace ErnestDefoe\Predictions\Access;

use ErnestDefoe\Predictions\Settings;
use Flarum\Discussion\Discussion;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What an actor may see (Extend\ModelVisibility, so every `whereVisibleTo`,
 * core's notification subjects included, applies it): the view permission,
 * and the discussion's visibility for one attached to a discussion.
 */
class ScopePredictionVisibility
{
    /** @param Builder<\ErnestDefoe\Predictions\Prediction> $query */
    public function __invoke(User $actor, Builder $query): void
    {
        if (! $actor->hasPermission(Settings::VIEW)) {
            $query->whereIn('predictions.id', []);

            return;
        }

        $query->where(function (Builder $q) use ($actor) {
            $q->whereNull('predictions.discussion_id')
                ->orWhereIn('predictions.discussion_id', Discussion::query()->whereVisibleTo($actor)->select('discussions.id'));
        });
    }
}
