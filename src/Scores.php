<?php

namespace ErnestDefoe\Predictions;

/**
 * The season table: one row per member, rebuilt from their scored picks.
 *
 * Rebuilt, never incremented, so a correction, a void or a deleted prediction
 * leaves it exactly right. Streaks follow the order predictions were settled.
 */
class Scores
{
    /** @param list<int> $userIds */
    public function rebuild(int $seasonId, array $userIds): void
    {
        foreach (array_chunk(array_values(array_unique($userIds)), 500) as $chunk) {
            $rows = Pick::query()
                ->join('predictions', 'predictions.id', '=', 'prediction_picks.prediction_id')
                ->where('predictions.season_id', $seasonId)
                ->whereNotNull('predictions.settled_at')
                ->whereNull('predictions.voided_at')
                ->whereNotNull('predictions.scored_at')
                ->whereIn('prediction_picks.user_id', $chunk)
                ->orderBy('predictions.settled_at')
                ->orderBy('predictions.id')
                ->get(['prediction_picks.user_id', 'prediction_picks.hit', 'prediction_picks.points']);

            $totals = [];
            foreach ($rows as $row) {
                $id = (int) $row->user_id;
                $t = $totals[$id] ?? ['season_id' => $seasonId, 'user_id' => $id, 'points' => 0, 'hits' => 0, 'settled' => 0, 'streak' => 0, 'best_streak' => 0];
                $t['settled']++;
                $t['points'] += (int) $row->points;
                if ($row->hit) {
                    $t['hits']++;
                    $t['streak']++;
                    $t['best_streak'] = max($t['best_streak'], $t['streak']);
                } else {
                    $t['streak'] = 0;
                }
                $totals[$id] = $t;
            }

            $gone = array_values(array_diff($chunk, array_keys($totals)));
            if ($gone) {
                Score::query()->where('season_id', $seasonId)->whereIn('user_id', $gone)->delete();
            }
            if ($totals) {
                Score::query()->upsert(array_values($totals), ['season_id', 'user_id'], ['points', 'hits', 'settled', 'streak', 'best_streak']);
            }
        }
    }

    /** Everyone with a scored pick in a prediction: used when one is deleted after scoring. */
    public function rebuildFor(Prediction $prediction): void
    {
        if ($prediction->season_id && $prediction->scored_at) {
            $userIds = Pick::query()->where('prediction_id', $prediction->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            $prediction->delete();
            $this->rebuild($prediction->season_id, $userIds);

            return;
        }

        $prediction->delete();
    }
}
