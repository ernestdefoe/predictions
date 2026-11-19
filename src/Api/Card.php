<?php

namespace ErnestDefoe\Predictions\Api;

use DateTimeInterface;

/**
 * Everything a prediction's card shows that depends on the viewer or on other
 * rows: the one definition Presenter fills (a page at a time), Cards batches
 * and PredictionResource exposes field by field.
 */
final class Card
{
    /**
     * @param list<array{id: int, label: string, count: int|null, isCorrect: bool|null}> $options
     * @param array<string, mixed>|null $stats count, avg, median, min, max, bins and the closest guessers
     * @param array{optionId: int|null, value: float|null, hit: bool|null, points: int|null}|null $myPick
     * @param array<string, bool> $can
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $picksVisible,
        public readonly int $picksCount,
        public readonly array $options,
        public readonly ?array $stats,
        public readonly ?float $answer,
        public readonly ?int $correctOptionId,
        public readonly ?DateTimeInterface $settledAt,
        public readonly bool $scored,
        /** Null until scored; false when too few picked for it to count on the leaderboard. */
        public readonly ?bool $counted,
        public readonly int $minPickers,
        public readonly ?array $myPick,
        public readonly string $url,
        public readonly array $can,
    ) {
    }
}
