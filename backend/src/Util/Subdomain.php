<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Derives an organization subdomain from its display name.
 *
 * The organization table stores a DNS label, so the name has to be reduced to
 * `[a-z0-9-]`, at most 63 characters, starting and ending with a letter or
 * digit. No transliteration is attempted: a name in a non-Latin script has no
 * Latin slug, so it falls back to the generic base and relies on the numeric
 * suffix for uniqueness.
 */
final class Subdomain
{
    private const MAX_LENGTH = 63;
    private const FALLBACK = 'organization';

    public static function fromName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        // Keep room for a "-123" collision suffix inside the 63 character limit.
        $slug = substr($slug, 0, self::MAX_LENGTH - 4);
        $slug = rtrim($slug, '-');

        return '' === $slug ? self::FALLBACK : $slug;
    }

    public static function withSuffix(string $base, int $attempt): string
    {
        if ($attempt <= 1) {
            return $base;
        }

        $suffix = '-'.$attempt;

        return rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;
    }
}
