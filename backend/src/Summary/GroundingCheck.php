<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * Proves that every figure in a generated summary is derivable from the facts.
 *
 * A model's prose is not evidence. This check walks the answer, pulls out every
 * number, percentage and currency amount — in English, French and Arabic
 * notation, including Arabic-Indic digits — and accepts it only when it equals a
 * fact, a two-fact sum or difference, or a two-fact percentage, within the
 * rounding the text itself displays (`1,234` may stand for 1234.4, `12.5%` may
 * stand for 12.47%). A figure that survives no derivation means the answer
 * invented something, and the caller replaces it with the deterministic
 * template instead of showing it.
 *
 * Identifiers (`INV-2026-77`, a hex digest) are removed before numbers are read:
 * they name a row, they do not make a claim about the facts.
 *
 * Floats appear here only to compare ratios and rounded displays; the facts stay
 * integer millimes and no total is computed by this class.
 */
final class GroundingCheck
{
    /**
     * A number with optional grouping (`1 234,50`, `1,234.50`) and an optional
     * percent sign. The lookbehind keeps digits inside an identifier
     * (`INV-2026`, a hash) from counting as a figure.
     */
    private const NUMBER_PATTERN = '/(?<![\p{L}\p{N}_])[-−+]?(?:\d{1,3}(?:[\x{00A0}\x{202F} ]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)*)(?:\s?[%٪])?/u';

    /** Alphanumeric runs that contain a letter and a separator: an identifier, not a figure. */
    private const IDENTIFIER_PATTERN = '~(?<![A-Za-z0-9])(?=[A-Za-z0-9._/-]*[A-Za-z])[A-Za-z0-9]+(?:[-._/][A-Za-z0-9]+)+~u';

    /** @var array<string, string> */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    public function verify(string $text, WorkspaceFacts $facts): bool
    {
        $scan = $this->scanText($text, $facts);

        if (0 === preg_match_all(self::NUMBER_PATTERN, $scan, $matches)) {
            return true;
        }

        [$values, $percentages] = $this->candidates($facts);

        foreach ($matches[0] as $token) {
            $number = $this->parse((string) $token, $facts->locale);
            if (null === $number) {
                return false;
            }
            if (!$this->matches($number['value'], $number['decimals'], $number['percent'] ? $percentages : $values)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Removes the strings a model is allowed to quote verbatim, so their digits
     * are not mistaken for invented figures: listed customer names and the ISO
     * dates the facts carry.
     */
    private function scanText(string $text, WorkspaceFacts $facts): string
    {
        $scan = $text;
        foreach ($facts->customerNames as $name) {
            if ('' === trim($name)) {
                continue;
            }
            $scan = preg_replace('~'.preg_quote($name, '~').'~iu', ' ', $scan) ?? $scan;
        }
        foreach ($facts->dates as $date) {
            if ('' !== $date) {
                $scan = str_replace($date, ' ', $scan);
            }
        }

        $scan = preg_replace(self::IDENTIFIER_PATTERN, ' ', $scan) ?? $scan;

        return strtr($scan, self::DIGITS);
    }

    /**
     * @return array{0: list<float>, 1: list<float>}
     */
    private function candidates(WorkspaceFacts $facts): array
    {
        $counts = [];
        foreach ($facts->counts() as $value) {
            $counts[] = (float) $value;
        }
        $money = [];
        foreach ($facts->millimes() as $value) {
            $money[] = $value / 10 ** WorkspaceFacts::MONEY_SCALE;
        }

        $values = [...$counts, ...$money];
        $percentages = [];
        foreach ([$counts, $money] as $group) {
            $values = [...$values, ...$this->sumsAndDifferences($group)];
            $percentages = [...$percentages, ...$this->percentages($group)];
        }

        return [$values, $percentages];
    }

    /**
     * @param list<float> $group
     *
     * @return list<float>
     */
    private function sumsAndDifferences(array $group): array
    {
        $derived = [];
        foreach ($group as $a) {
            foreach ($group as $b) {
                $derived[] = $a + $b;
                $derived[] = abs($a - $b);
            }
        }

        return $derived;
    }

    /**
     * @param list<float> $group
     *
     * @return list<float>
     */
    private function percentages(array $group): array
    {
        $ratios = [];
        foreach ($group as $a) {
            foreach ($group as $b) {
                if ($b <= 0.0) {
                    continue;
                }
                $ratio = $a / $b * 100;
                $ratios[] = $ratio;
                $ratios[] = 100 - $ratio;
            }
        }

        return $ratios;
    }

    /**
     * @param list<float> $pool
     */
    private function matches(float $value, int $decimals, array $pool): bool
    {
        // Half of the last displayed digit: what rounding to that precision allows.
        $tolerance = 0.5 * (10 ** -$decimals) + 1e-9;
        foreach ($pool as $candidate) {
            if (abs($candidate - $value) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turns one token into a value using the locale's separators.
     *
     * @return array{value: float, decimals: int, percent: bool}|null
     */
    private function parse(string $token, string $locale): ?array
    {
        $percent = str_contains($token, '%') || str_contains($token, '٪');
        $token = trim(str_replace(['%', '٪', "\u{00A0}", "\u{202F}"], ['', '', ' ', ' '], $token));

        $negative = str_starts_with($token, '-') || str_starts_with($token, '−');
        $token = ltrim($token, '+-−');

        // Spaces were only allowed as thousands separators by the pattern.
        $token = str_replace(' ', '', $token);
        if ('' === $token || 1 !== preg_match('/^\d[\d.,]*$/u', $token)) {
            return null;
        }

        $decimal = $this->decimalSeparator($locale);
        $thousands = $this->thousandsSeparator($locale);
        $dot = substr_count($token, '.');
        $comma = substr_count($token, ',');

        if ($dot > 0 && $comma > 0) {
            // The last separator is the decimal one: 1,234.50 or 1.234,50.
            $position = max((int) strrpos($token, '.'), (int) strrpos($token, ','));
            $integer = str_replace(['.', ','], '', substr($token, 0, $position));
            $fraction = substr($token, $position + 1);
        } elseif (0 === $dot + $comma) {
            $integer = $token;
            $fraction = '';
        } else {
            $separator = $dot > 0 ? '.' : ',';
            $positions = substr_count($token, $separator);
            $after = substr($token, (int) strrpos($token, $separator) + 1);
            $isDecimal = 1 === $positions
                && ($separator === $decimal || ($separator !== $thousands && 3 !== strlen($after)));

            $integer = $isDecimal ? substr($token, 0, (int) strrpos($token, $separator)) : str_replace($separator, '', $token);
            $fraction = $isDecimal ? $after : '';
        }

        $normalized = '' === $fraction ? $integer : $integer.'.'.$fraction;
        if (1 !== preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return null;
        }

        $value = (float) $normalized;

        return [
            'value' => $negative ? -$value : $value,
            'decimals' => strlen($fraction),
            'percent' => $percent,
        ];
    }

    private function decimalSeparator(string $locale): string
    {
        return 'fr' === $locale ? ',' : '.';
    }

    private function thousandsSeparator(string $locale): string
    {
        return 'fr' === $locale ? ' ' : ',';
    }
}
