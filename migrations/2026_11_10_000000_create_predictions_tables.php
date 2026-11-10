<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        // A season holds the leaderboard. Exactly one is open (ended_at null) at a time.
        $schema->create('prediction_seasons', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 80);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
        });

        $schema->create('predictions', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('discussion_id')->nullable();
            $table->unsignedInteger('season_id')->nullable();
            $table->string('type', 10);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('unit', 24)->nullable();
            $table->double('tolerance')->nullable();
            $table->boolean('hide_picks')->default(false);
            $table->dateTime('lock_at');
            // Set once, by whichever of the scheduler or a settle gets there first.
            $table->dateTime('locked_at')->nullable();
            $table->unsignedInteger('picks_count')->default(0);
            // Number type: count, average, median, range and a histogram, kept with every pick.
            $table->json('stats')->nullable();
            $table->unsignedInteger('correct_option_id')->nullable();
            $table->double('answer_value')->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedInteger('settled_by_id')->nullable();
            $table->dateTime('voided_at')->nullable();
            // Null until the scoring job has written every pick's result for the current answer.
            $table->dateTime('scored_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
            $table->foreign('settled_by_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('season_id')->references('id')->on('prediction_seasons')->nullOnDelete();
            $table->index(['lock_at', 'locked_at']);
            $table->index(['settled_at', 'scored_at']);
            $table->index('discussion_id');
        });

        $schema->create('prediction_options', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('prediction_id');
            $table->string('label', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('picks_count')->default(0);

            $table->foreign('prediction_id')->references('id')->on('predictions')->cascadeOnDelete();
        });

        $schema->create('prediction_picks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('prediction_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('option_id')->nullable();
            $table->double('value')->nullable();
            $table->boolean('hit')->nullable();
            $table->unsignedSmallInteger('points')->nullable();
            $table->timestamps();

            $table->unique(['prediction_id', 'user_id']);
            $table->index(['user_id', 'prediction_id']);
            $table->foreign('prediction_id')->references('id')->on('predictions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('option_id')->references('id')->on('prediction_options')->nullOnDelete();
        });

        // One row per member per season, rebuilt from their picks whenever a prediction they picked is scored.
        $schema->create('prediction_scores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('season_id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedInteger('settled')->default(0);
            $table->unsignedInteger('streak')->default(0);
            $table->unsignedInteger('best_streak')->default(0);

            $table->unique(['season_id', 'user_id']);
            $table->index(['season_id', 'points']);
            $table->foreign('season_id')->references('id')->on('prediction_seasons')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Who was named Oracle at the end of each season (so the group can move on next season).
        $schema->create('prediction_awards', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('season_id');
            $table->unsignedInteger('user_id');
            $table->unsignedSmallInteger('rank');
            $table->unsignedInteger('points');

            $table->unique(['season_id', 'user_id']);
            $table->foreign('season_id')->references('id')->on('prediction_seasons')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->getConnection()->table('prediction_seasons')->insert([
            'name' => 'Season 1',
            'started_at' => \Carbon\Carbon::now(),
        ]);
    },

    'down' => function (Builder $schema) {
        $schema->dropIfExists('prediction_awards');
        $schema->dropIfExists('prediction_scores');
        $schema->dropIfExists('prediction_picks');
        $schema->dropIfExists('prediction_options');
        $schema->dropIfExists('predictions');
        $schema->dropIfExists('prediction_seasons');
    },
];
