<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use DateTimeInterface;
use ErnestDefoe\Predictions\Refusal;

/** Cleaning and checking what the prediction form sends, beyond each field's own rules (lengths, types). Every refusal names what's wrong. */
final class Input
{
    public const MAX_OPTIONS = 12;
    public const MAX_DAYS = 730;

    public static function title(mixed $value): string
    {
        // The field allows 3 to 150 characters; spaces collapsed, it can be shorter than 3 again.
        $title = self::line($value);
        if (mb_strlen($title) < 3) {
            throw new Refusal('predictions_bad_title');
        }

        return $title;
    }

    public static function description(mixed $value): ?string
    {
        // Lengths are the field's rule; this only tidies.
        $text = trim(str_replace("\r\n", "\n", is_string($value) ? $value : ''));

        return $text === '' ? null : $text;
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

    /** A lock time at least a minute away and no more than two years. */
    public static function lockAt(?DateTimeInterface $at): Carbon
    {
        $at = $at ? Carbon::instance($at)->utc() : null;
        if (! $at || $at->lt(Carbon::now()->addMinute()) || $at->gt(Carbon::now()->addDays(self::MAX_DAYS))) {
            throw new Refusal('predictions_bad_lock');
        }

        return $at;
    }

    public static function unit(mixed $value): ?string
    {
        $unit = self::line($value);

        return $unit === '' ? null : $unit;
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
