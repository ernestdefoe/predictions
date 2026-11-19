<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Settings;
use Flarum\Api\Context;
use Flarum\Api\Schema;

/** The viewer's own Predictions permissions on the forum resource: their groups are already loaded, so no query. */
class ForumFields
{
    /** @return list<Schema\Boolean> */
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('predictionsCanView')
                ->get(fn (object $forum, Context $context) => $context->getActor()->hasPermission(Settings::VIEW)),
            Schema\Boolean::make('predictionsCanCreate')
                ->get(fn (object $forum, Context $context) => ! $context->getActor()->isGuest() && $context->getActor()->hasPermission(Settings::CREATE)),
            Schema\Boolean::make('predictionsCanModerate')
                ->get(fn (object $forum, Context $context) => $context->getActor()->hasPermission(Settings::MODERATE)),
        ];
    }
}
