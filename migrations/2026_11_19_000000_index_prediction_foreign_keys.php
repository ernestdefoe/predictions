<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Indexes for the columns every lookup by member or by prediction uses: MySQL makes
 * one for each foreign key by itself, PostgreSQL and SQLite don't. Names are left to
 * Laravel, which prefixes them, so two forums sharing a database never collide.
 */
$columns = [
    'predictions' => ['user_id', 'season_id', 'settled_by_id'],
    'prediction_options' => ['prediction_id'],
    'prediction_picks' => ['option_id'],
    'prediction_scores' => ['user_id'],
    'prediction_awards' => ['user_id'],
];

return [
    'up' => function (Builder $schema) use ($columns) {
        foreach ($columns as $table => $list) {
            $schema->table($table, function (Blueprint $table) use ($list) {
                foreach ($list as $column) {
                    $table->index($column);
                }
            });
        }
    },
    'down' => function (Builder $schema) use ($columns) {
        // MySQL let each foreign key use these in place of the index it made for itself, and
        // won't drop an index a foreign key needs: there they stay, as the keys had one before.
        if ($schema->getConnection()->getDriverName() === 'mysql') {
            return;
        }
        foreach ($columns as $table => $list) {
            $schema->table($table, function (Blueprint $table) use ($list) {
                foreach ($list as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    },
];
