<?php

/*
 * Predictions: members call it before it happens.
 *
 * A prediction is multiple choice, yes/no or a number scored by closeness,
 * with a lock time. Picks are judged against the lock time under a row lock
 * (Picks), settling writes the answer under the same lock and queues the
 * scoring (Settler → Job\ScorePrediction → Scorer), and the scheduler stamps
 * locks and catches up any scoring a queue lost (predictions:tick). Every
 * step can run twice without changing the result.
 *
 * The season table (prediction_scores) is rebuilt from picks for exactly the
 * members a prediction touched, so the leaderboard is one indexed query.
 */

use ErnestDefoe\Predictions\Api;
use ErnestDefoe\Predictions\Console\TickCommand;
use ErnestDefoe\Predictions\Http\PageContent;
use ErnestDefoe\Predictions\Listener\AttachFromPost;
use ErnestDefoe\Predictions\Notification;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Settings;
use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Discussion\Event\Deleted as DiscussionDeleted;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        // The page, the form and the card's embed are chunks in here; undeclared chunks 404.
        ->jsDirectory(__DIR__.'/js/dist/forum')
        ->css(__DIR__.'/less/forum.less')
        ->route('/predictions', 'predictions', PageContent::class)
        ->route('/predictions/{id:\d+}', 'predictions.show', PageContent::class),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/less/admin.less'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Routes('api'))
        ->get('/predictions', 'predictions.index', Api\ListController::class)
        ->post('/predictions', 'predictions.create', Api\SaveController::class)
        ->get('/predictions/{id:\d+}', 'predictions.show', Api\ShowController::class)
        ->patch('/predictions/{id:\d+}', 'predictions.update', Api\SaveController::class)
        ->delete('/predictions/{id:\d+}', 'predictions.delete', Api\DeleteController::class)
        ->post('/predictions/{id:\d+}/pick', 'predictions.pick', Api\PickController::class)
        ->delete('/predictions/{id:\d+}/pick', 'predictions.unpick', Api\PickController::class)
        ->post('/predictions/{id:\d+}/settle', 'predictions.settle', Api\SettleController::class)
        ->get('/predictions/{id:\d+}/picks', 'predictions.picks', Api\PicksController::class)
        ->get('/prediction-leaderboard', 'predictions.leaderboard', Api\LeaderboardController::class)
        ->post('/prediction-seasons', 'predictions.seasons.create', Api\SeasonController::class),

    new Extend\ApiResource(Api\PredictionResource::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserFields::class),

    // The viewer's own permissions: their groups are already loaded, so no query.
    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('predictionsCanView')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission(Settings::VIEW)),
            Schema\Boolean::make('predictionsCanCreate')
                ->get(fn ($forum, Context $context) => ! $context->getActor()->isGuest() && $context->getActor()->hasPermission(Settings::CREATE)),
            Schema\Boolean::make('predictionsCanModerate')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission(Settings::MODERATE)),
        ]),

    (new Extend\Notification())
        ->type(Notification\PredictionCalledBlueprint::class, ['alert'])
        ->type(Notification\PredictionMissedBlueprint::class, ['alert'])
        ->type(Notification\PredictionLockedBlueprint::class, ['alert']),

    (new Extend\Settings())
        ->default(Settings::NAV, true)
        ->default(Settings::BOLD_BONUS, true)
        ->default(Settings::ORACLE_COUNT, Settings::DEFAULT_ORACLE_COUNT)
        ->default(Settings::ORACLE_BADGE, true)
        ->serializeToForum('predictionsNav', Settings::NAV, 'boolval')
        ->serializeToForum('predictionsBoldBonus', Settings::BOLD_BONUS, 'boolval'),

    (new Extend\Event())
        ->listen(Posted::class, AttachFromPost::class)
        // The foreign key does this too, where the database enforces it (SQLite may not).
        ->listen(DiscussionDeleted::class, function (DiscussionDeleted $event) {
            Prediction::query()->where('discussion_id', $event->discussion->id)->update(['discussion_id' => null]);
        }),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->schedule(TickCommand::class, function (ScheduledEvent $event) {
            $event->everyMinute()->withoutOverlapping();
        }),
];
