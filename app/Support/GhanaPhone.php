<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one phone validator (brief §0.4). Mirrors ../mefs/src/lib/validation/phone.ts —
 * same regex, same normal form, same "null means invalid, handle it" contract.
 *
 * Ghana: `+233XXXXXXXXX` or `0XXXXXXXXX`, the digit after the prefix being 2-9.
 *
 * Storage is always E.164. A customer who types `024 123 4567` on the web and `+233 24 123
 * 4567` on the phone is one customer, and they only look like one if both are normalised
 * before they are compared or looked up.
 */
final class GhanaPhone
{
    private const PATTERN = '/^(?:\+233|0)[2-9]\d{8}$/';

    /** Strip the separators people actually type. */
    public static function strip(string $input): string
    {
        return preg_replace('/[\s()\-]/', '', $input) ?? $input;
    }

    public static function isValid(string $input): bool
    {
        return preg_match(self::PATTERN, self::strip($input)) === 1;
    }

    /** Canonical `+233XXXXXXXXX`, or null. Callers must handle the null, never assume. */
    public static function normalise(string $input): ?string
    {
        $cleaned = self::strip($input);

        if (preg_match(self::PATTERN, $cleaned) !== 1) {
            return null;
        }

        return str_starts_with($cleaned, '0')
            ? '+233'.substr($cleaned, 1)
            : $cleaned;
    }
}
