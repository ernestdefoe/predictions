<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Refusal;

/** Reading and checking what the prediction form sends. Every refusal names the field. */
final class Input
{
    public const MAX_OPTIONS = 12;
    public const MAX_DAYS = 730;

    public static function title(mixed $value): string
    {
        $title = self::line($value);
        if (mb_strlen($title) < 3 || mb_strlen($title) > 150) {
            throw new Refusal('predictions_bad_title');
        }

        return $title;
    }

    public static function description(mixed $value): ?string
    {
        $text = trim(str_replace("\r\n", "\n", is_string($value) ? $value : ''));
        if (mb_strlen($text) > 1000) {
            throw new Refusal('predictions_bad_description');
        }

        return $text === '' ? null : $text;
    }

    public static function type(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, Prediction::TYPES, true)) {
            throw new Refusal('predictions_bad_type');
        }

        return $value;
    }

    /** @return list<string> */
    public static function options(mixed $value): array
    {
        $labels = [];
        foreach (is_array($value) ? $value : [] as $label) {
            $label = self::line($label);
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 80) {
                throw new Refusal('predictions_bad_options');
            }
            $labels[mb_strtolower($label)] = $label;
        }

        if (count($labels) < 2 || count($labels) > self::MAX_OPTIONS) {
            throw new Refusal('predictions_bad_options');
        }

        return array_values($labels);
    }

    public static function lockAt(mixed $value): Carbon
    {
        try {
            $at = is_string($value) && $value !== '' ? Carbon::parse($value)->utc() : null;
        } catch (\Throwable) {
            $at = null;
        }

        if (! $at || $at->lt(Carbon::now()->addMinute()) || $at->gt(Carbon::now()->addDays(self::MAX_DAYS))) {
            throw new Refusal('predictions_bad_lock');
        }

        return $at;
    }

    public static function unit(mixed $value): ?string
    {
        $unit = self::line($value);
        if (mb_strlen($unit) > 24) {
            throw new Refusal('predictions_bad_unit');
        }

        return $unit === '' ? null : $unit;
    }

    public static function tolerance(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1e9) {
            throw new Refusal('predictions_bad_tolerance');
        }

        return (float) $value ?: null;
    }

    public static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    public static function id(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** One line of plain text: control characters and runs of whitespace collapse to a space. */
    private static function line(mixed $value): string
    {
        return is_string($value) ? trim((string) preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $value)) : '';
    }
}
