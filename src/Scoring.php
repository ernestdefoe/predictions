<?php

namespace ErnestDefoe\Predictions;

/**
 * How a pick scores, as plain arithmetic so it can be tested on its own.
 *
 * Choice and yes/no: a hit is worth 10, plus up to 10 more for a bold call
 * (the fewer people who saw it coming, the more it's worth): 10 + round(10 x
 * (1 - share of picks on the answer)). Called by everyone = 10; by 1 in 10 = 19.
 *
 * Number: the closest guess (ties included) calls it, for 20, once two or more people guessed. With a
 * "within" range set, every other guess inside it is a hit too, for 5 to 10
 * on a sliding scale. Everything else is a miss for 0.
 */
final class Scoring
{
    public const HIT = 10;
    public const BOLD_MAX = 10;
    public const CLOSEST = 20;
    public const WITHIN_MIN = 5;
    public const WITHIN_MAX = 10;

    /**
     * @param array<int, int|null> $optionByPick pick id => option id
     * @return array<int, array{hit: bool, points: int}>
     */
    public static function choice(array $optionByPick, int $correct, bool $boldBonus): array
    {
        $total = count($optionByPick);
        $right = count(array_filter($optionByPick, fn ($option) => $option === $correct));
        $points = self::HIT + ($boldBonus && $total > 0 ? (int) round(self::BOLD_MAX * (1 - $right / $total)) : 0);

        $out = [];
        foreach ($optionByPick as $id => $option) {
            $hit = $option === $correct;
            $out[$id] = ['hit' => $hit, 'points' => $hit ? $points : 0];
        }

        return $out;
    }

    /**
     * @param array<int, float|null> $valueByPick pick id => guess
     * @return array<int, array{hit: bool, points: int}>
     */
    public static function number(array $valueByPick, float $answer, ?float $tolerance): array
    {
        $errors = [];
        foreach ($valueByPick as $id => $value) {
            $errors[$id] = $value === null ? INF : abs($value - $answer);
        }

        // "Closest" means something only when at least two people guessed.
        $best = count(array_filter($valueByPick, fn ($v) => $v !== null)) >= 2 ? min($errors) : INF;
        $out = [];
        foreach ($errors as $id => $error) {
            if ($error !== INF && self::same($error, $best)) {
                $out[$id] = ['hit' => true, 'points' => self::CLOSEST];
            } elseif ($tolerance !== null && $tolerance > 0 && $error <= $tolerance + 1e-9) {
                $out[$id] = ['hit' => true, 'points' => self::WITHIN_MIN + (int) round((self::WITHIN_MAX - self::WITHIN_MIN) * (1 - $error / $tolerance))];
            } else {
                $out[$id] = ['hit' => false, 'points' => 0];
            }
        }

        return $out;
    }

    /**
     * Count, average, median, range and a ten-bar histogram of the guesses.
     *
     * @param list<float> $values
     * @return array{count: int, avg: float, median: float, min: float, max: float, bins: list<int>}|null
     */
    public static function stats(array $values): ?array
    {
        if (! $values) {
            return null;
        }

        sort($values);
        $count = count($values);
        $min = $values[0];
        $max = $values[$count - 1];
        $mid = intdiv($count, 2);
        $median = $count % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        $bins = array_fill(0, 10, 0);
        $span = $max - $min;
        foreach ($values as $value) {
            $bins[$span > 0 ? min(9, (int) floor(($value - $min) / $span * 10)) : 0]++;
        }

        return [
            'count' => $count,
            'avg' => round(array_sum($values) / $count, 4),
            'median' => round($median, 4),
            'min' => $min,
            'max' => $max,
            'bins' => $bins,
        ];
    }

    private static function same(float $a, float $b): bool
    {
        return abs($a - $b) < 1e-9;
    }
}
