<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;

/**
 * The ids of a discussion's own predictions, on the discussion page only (one
 * indexed query there, none on lists): the first post shows their cards.
 */
class DiscussionFields
{
    /** @return list<Schema\Arr> */
    public function __invoke(): array
    {
        return [
            Schema\Arr::make('predictionIds')
                ->visible(fn (Discussion $discussion, Context $context) => $context->showing(DiscussionResource::class) && $context->getActor()->hasPermission(Settings::VIEW))
                ->get(fn (Discussion $discussion) => Prediction::query()->where('discussion_id', $discussion->id)->orderBy('id')->limit(10)->pluck('id')->map(fn ($id) => (int) $id)->all()),
        ];
    }
}
