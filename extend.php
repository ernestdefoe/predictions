<?php

/*
 * Predictions: members call it before it happens.
 *
 * A prediction is multiple choice, yes/no or a number scored by closeness,
 * with a lock time. Picks are judged against the lock time under the
 * prediction's RowLock (Picks), settling writes the answer under it and queues the
 * scoring (Settler → Job\ScorePrediction → Scorer), and the scheduler stamps
 * locks and catches up any scoring a queue lost (predictions:tick). Every
 * step can run twice without changing the result.
 *
 * The season table (prediction_scores) is rebuilt from picks for exactly the
 * members a prediction touched, so the leaderboard is one indexed query.
 */

use ErnestDefoe\Predictions\Access\PredictionPolicy;
use ErnestDefoe\Predictions\Access\ScopePredictionVisibility;
use ErnestDefoe\Predictions\Api;
use ErnestDefoe\Predictions\Console\TickCommand;
use ErnestDefoe\Predictions\Http\PageContent;
use ErnestDefoe\Predictions\Listener\AttachFromPost;
use ErnestDefoe\Predictions\Listener\Cleanup;
use ErnestDefoe\Predictions\Notification;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Provider\BadgesServiceProvider;
use ErnestDefoe\Predictions\Provider\PredictionsServiceProvider;
use ErnestDefoe\Predictions\Refusal;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Discussion\Event\Deleting as DiscussionDeleting;
use Flarum\Extend;
use Flarum\Post\Event\Posted;
use Flarum\User\Event\Deleting as UserDeleting;
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

    // Predictions, who picked what, seasons and the leaderboard's standings.
    new Extend\ApiResource(Api\PredictionResource::class),
    new Extend\ApiResource(Api\PickResource::class),
    new Extend\ApiResource(Api\SeasonResource::class),
    new Extend\ApiResource(Api\StandingResource::class),

    // A prediction is as visible as its discussion (or, standalone, to anyone who may view predictions).
    (new Extend\ModelVisibility(Prediction::class))
        ->scope(ScopePredictionVisibility::class),

    (new Extend\Policy())
        ->modelPolicy(Prediction::class, PredictionPolicy::class),

    (new Extend\ErrorHandling())
        ->handler(Refusal::class, Api\RefusalHandler::class),

    (new Extend\ServiceProvider())
        ->register(PredictionsServiceProvider::class),

    // Oracles get FoF Badges' badge only while it is enabled; nothing of theirs loads otherwise.
    (new Extend\Conditional())
        ->whenExtensionEnabled('fof-badges', fn () => [
            (new Extend\ServiceProvider())->register(BadgesServiceProvider::class),
        ]),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(Api\DiscussionFields::class),

    (new Extend\ApiResource(UserResource::class))
        ->fields(Api\UserFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(Api\ForumFields::class),

    (new Extend\Notification())
        ->type(Notification\PredictionCalledBlueprint::class, ['alert'])
        ->type(Notification\PredictionMissedBlueprint::class, ['alert'])
        ->type(Notification\PredictionLockedBlueprint::class, ['alert']),

    // String keys, not Settings:: constants: reading the extension's own classes while
    // extend.php loads fatals every request during a deploy that is still swapping them.
    (new Extend\Settings())
        ->default('ernestdefoe-predictions.nav', true)
        ->default('ernestdefoe-predictions.bold_bonus', true)
        ->default('ernestdefoe-predictions.oracle_count', 3)
        ->default('ernestdefoe-predictions.oracle_group', '')
        ->default('ernestdefoe-predictions.oracle_badge', true)
        ->default('ernestdefoe-predictions.min_pickers', 3)
        ->default('ernestdefoe-predictions.daily_limit', 20)
        ->serializeToForum('predictionsNav', 'ernestdefoe-predictions.nav', 'boolval')
        ->serializeToForum('predictionsBoldBonus', 'ernestdefoe-predictions.bold_bonus', 'boolval'),

    (new Extend\Event())
        ->listen(Posted::class, AttachFromPost::class)
        // The foreign key does this too, where the database enforces it (SQLite may not).
        ->listen(DiscussionDeleting::class, [Cleanup::class, 'discussion'])
        ->listen(UserDeleting::class, [Cleanup::class, 'user']),

    (new Extend\Console())
        ->command(TickCommand::class)
        ->schedule(TickCommand::class, function (ScheduledEvent $event) {
            $event->everyMinute()->withoutOverlapping();
        }),
];
