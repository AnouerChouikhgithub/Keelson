<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * Proves that every quantitative claim in a generated summary is derivable from
 * the facts.
 *
 * A model's prose is not evidence. This check walks the answer and refuses it
 * unless every quantity in it can be derived. Three things count as a quantity
 * claim:
 *
 *  - digits, in English, French and Arabic notation, including Arabic-Indic
 *    digits and the locale's own separators;
 *  - a number written in words ("five", "douze", "خمسة", "quatre-vingt-dix"),
 *    composed the way the language composes them (up to hundreds and thousands);
 *  - a percentage, whether written with `%` or with the language's percent word.
 *
 * Each claim must equal a fact, a two-fact sum or difference, or a two-fact
 * percentage, within the rounding the text itself displays (`1,234` may stand for
 * 1234.4, `12.5%` may stand for 12.47%). A figure that survives no derivation
 * means the answer invented something, and the caller replaces it with the
 * deterministic template instead of showing it.
 *
 * Vague quantity words ("most", "presque", "معظم") are refused outright: they
 * make a claim the facts cannot prove, and there is no way to check one. The
 * word lists are closed sets — wording outside them is not detected, which is a
 * documented limitation rather than a promise ({@see docs/ai.md}).
 *
 * Identifiers (`INV-2026-77`, a hex digest) are removed before numbers are read:
 * they name a row, they do not make a claim about the facts. Two documented
 * exclusions keep legitimate prose from being refused: the French articles
 * `un`/`une` count as the number 1 only inside a composition ("vingt et un"),
 * and a percentage written in words is still checked as its plain value.
 *
 * Floats appear here only to compare ratios and rounded displays; the facts stay
 * integer millimes and no total is computed by this class.
 */
final class GroundingCheck
{
    /**
     * A number with optional grouping (`1 234,50`, `1,234.50`) and an optional
     * percent sign. The lookbehind keeps digits inside a Latin identifier
     * (`INV-2026`, a hash) from counting as a figure, while still reading a number
     * that Arabic attaches a proclitic to — the fallback template itself writes
     * `و203٫250`, and "and 203.250" is one amount, not the number 250.
     */
    private const NUMBER_PATTERN = '/(?<![A-Za-z0-9_])[-−+]?(?:\d{1,3}(?:[\x{00A0}\x{202F} ]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)*)(?:\s?[%٪])?/u';

    /**
     * An identifier: a separator-joined run that carries both a letter and a
     * digit (`INV-2026-77`, `a1b2-c3d4`). A digit-free hyphenated phrase is
     * prose, not an identifier — `quatre-vingt-onze` and `twenty-one` are number
     * words and must survive this removal to be read as claims.
     */
    private const IDENTIFIER_PATTERN = '~(?<![A-Za-z0-9])(?=[A-Za-z0-9._/-]*[A-Za-z])(?=[A-Za-z0-9._/-]*\d)[A-Za-z0-9]+(?:[-._/][A-Za-z0-9]+)+~u';

    /** @var array<string, string> */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => ',',
    ];

    /**
     * Quantity wording that cannot be proved from facts. Matched on the scanned
     * text (customer names and dates removed), whole-word, case-insensitively.
     * Multi-word entries make the risky single words safe: "about" is ordinary
     * prose, "about a third" is a claim.
     *
     * @var array<string, list<string>>
     */
    private const VAGUE_TERMS = [
        'en' => [
            'half', 'most', 'majority', 'almost all', 'nearly', 'approximately',
            'roughly', 'several', 'a third', 'two thirds', 'three quarters',
            'a quarter', 'almost half', 'more than half', 'less than half',
            'about a third', 'about half', 'the vast majority', 'a handful', 'dozens of',
        ],
        'fr' => [
            'moitié', 'moitie', 'majorité', 'majorite', 'presque', 'environ', 'la plupart',
            'plusieurs', 'quelques', 'quasiment', 'approximativement', 'un tiers',
            'deux tiers', 'trois quarts', 'un quart', 'une poignée', 'des dizaines de',
        ],
        'ar' => [
            'نصف', 'معظم', 'اغلب', 'تقريبا', 'ثلث', 'ثلثان', 'ربع', 'الغالبيه',
            'غالبيه', 'اكثر', 'بعض', 'قليل', 'حوالي', 'نحو',
        ],
    ];

    /**
     * Number words, already in the normalised form the tokeniser produces
     * (lower case; for Arabic: no diacritics or tatweel, alef variants folded to
     * `ا`, `ة` folded to `ه`, `ى` to `ي`).
     *
     * @var array<string, array<string, int>>
     */
    private const WORD_VALUES = [
        'en' => [
            'zero' => 0, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14,
            'fifteen' => 15, 'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18,
            'nineteen' => 19, 'twenty' => 20, 'thirty' => 30, 'forty' => 40,
            'fifty' => 50, 'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90,
        ],
        'fr' => [
            'zéro' => 0, 'zero' => 0, 'deux' => 2, 'trois' => 3, 'quatre' => 4,
            'cinq' => 5, 'six' => 6, 'sept' => 7, 'huit' => 8, 'neuf' => 9, 'dix' => 10,
            'onze' => 11, 'douze' => 12, 'treize' => 13, 'quatorze' => 14, 'quinze' => 15,
            'seize' => 16, 'vingt' => 20, 'trente' => 30, 'quarante' => 40,
            'cinquante' => 50, 'soixante' => 60,
            // `un`/`une` are handled by the caller: as articles they are not claims.
            'un' => 1, 'une' => 1,
        ],
        'ar' => [
            'صفر' => 0,
            'واحد' => 1, 'واحده' => 1, 'احد' => 1,
            'اثنان' => 2, 'اثنين' => 2, 'اثنتان' => 2, 'اثنتين' => 2, 'اثنا' => 2,
            'ثلاثه' => 3, 'ثلاث' => 3,
            'اربعه' => 4, 'اربع' => 4,
            'خمسه' => 5, 'خمس' => 5,
            'سته' => 6, 'ست' => 6,
            'سبعه' => 7, 'سبع' => 7,
            'ثمانيه' => 8, 'ثماني' => 8, 'ثمان' => 8,
            'تسعه' => 9, 'تسع' => 9,
            'عشره' => 10, 'عشر' => 10,
            'احدعشر' => 11,
            'عشرون' => 20, 'عشرين' => 20,
            'ثلاثون' => 30, 'ثلاثين' => 30,
            'اربعون' => 40, 'اربعين' => 40,
            'خمسون' => 50, 'خمسين' => 50,
            'ستون' => 60, 'ستين' => 60,
            'سبعون' => 70, 'سبعين' => 70,
            'ثمانون' => 80, 'ثمانين' => 80,
            'تسعون' => 90, 'تسعين' => 90,
        ],
    ];

    /**
     * Multipliers. A scale word alone ("hundred", "mille") is 1 × the scale, and
     * composes to the left the way the language does ("two hundred", "deux cents").
     *
     * @var array<string, array<string, int>>
     */
    private const SCALES = [
        'en' => ['hundred' => 100, 'thousand' => 1000],
        'fr' => ['cent' => 100, 'cents' => 100, 'mille' => 1000],
        'ar' => [
            'مئه' => 100, 'مايه' => 100, 'مائة' => 100,
            'مئتان' => 200, 'مئتين' => 200, 'مائتان' => 200, 'مائتين' => 200,
            'الف' => 1000, 'الاف' => 1000, 'الفان' => 2000, 'الفين' => 2000,
        ],
    ];

    /** @var array<string, list<string>> */
    private const CONNECTORS = [
        'en' => ['and'],
        'fr' => ['et'],
        'ar' => ['و'],
    ];

    /** Percent wording that turns a number before it into a percentage claim. */
    private const PERCENT_WORD_PATTERN = '/^\s*(?:%|٪|percent|per cent|pour cent|pourcent|بالمئه|في المئه|بالمائة)/iu';

    /** @api The boolean predicate the eval and red-team suites assert; the service uses rejectionReason() for the code. */
    public function verify(string $text, WorkspaceFacts $facts): bool
    {
        return null === $this->rejectionReason($text, $facts);
    }

    /**
     * Why the text was rejected, as a metadata-only code for the log: never the
     * rejected text, and never the token, because both may contain tenant content.
     */
    public function rejectionReason(string $text, WorkspaceFacts $facts): ?string
    {
        $scan = $this->scanText($text, $facts);

        if ($this->hasVagueQuantity($scan, $facts->locale)) {
            return 'vague_quantity';
        }

        [$values, $percentages] = $this->candidates($facts);
        // A model may copy the millimes fact in its raw unit. "11956600 millimes" names the
        // same money as 11956.600 TND; the unit word is required, so a mis-scaled
        // "11956600 TND" is still rejected.
        $values = [...$values, ...$this->millimeAmounts($scan, $facts->locale)];

        if (preg_match_all(self::NUMBER_PATTERN, $scan, $matches) > 0) {
            foreach ($matches[0] as $token) {
                $number = $this->parse((string) $token, $facts->locale);
                if (null === $number) {
                    return 'unreadable_number';
                }
                $pool = $number['percent'] ? $percentages : $values;
                if ($this->matches($number['value'], $number['decimals'], $pool)) {
                    continue;
                }
                // French writes the decimal comma, but a model copying an English-style
                // `11956.600` is still naming the fact 11956.6. The thousands reading was
                // tried first and did not match, so accepting the decimal reading here does
                // not let an invented figure through — it has to equal a fact either way.
                if ('fr' === $facts->locale
                    && !$number['percent']
                    && 1 === preg_match('/^\d+\.\d{3}$/', (string) $token)
                    && $this->matches((float) $token, 3, $values)) {
                    continue;
                }

                return 'ungrounded_number';
            }
        }

        foreach ($this->spelledNumbers($scan, $facts->locale) as $claim) {
            $pool = $claim['percent'] ? $percentages : $values;
            if (!$this->matches((float) $claim['value'], 0, $pool)) {
                return 'ungrounded_number_word';
            }
        }

        return null;
    }

    /**
     * Removes the strings a model is allowed to quote verbatim, so their digits
     * are not mistaken for invented figures: listed customer names and the ISO
     * dates the facts carry. Identifiers go too.
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

        $scan = $this->withoutDates($scan);

        $scan = preg_replace(self::IDENTIFIER_PATTERN, ' ', $scan) ?? $scan;

        return strtr($scan, self::DIGITS);
    }

    /**
     * Amounts written with the explicit millime unit, returned in their raw unit.
     *
     * A separate pass keeps unit and number together, so only a number the text itself
     * labels as millimes is compared against a raw fact; the same digits labelled TND
     * stay a mis-scaled amount and are rejected.
     *
     * @return list<float>
     */
    private function millimeAmounts(string $scan, string $locale): array
    {
        if (0 === preg_match_all('~(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)*)\s*(?:millimes?|ملليم|مليم)~iu', $scan, $matches)) {
            return [];
        }

        $amounts = [];
        foreach ($matches[1] as $raw) {
            $number = $this->parse((string) $raw, $locale);
            if (null !== $number) {
                $amounts[] = $number['value'];
            }
        }

        return $amounts;
    }

    /**
     * Removes a date written in any of the product's languages, not only the exact ISO
     * string the facts carry. Live French answered "25 novembre 2026" and live Arabic
     * "2026‑11‑25" with a non-breaking hyphen; both left the year `2026` to be read as
     * an invented number and rejected an otherwise correct summary.
     */
    private function withoutDates(string $scan): string
    {
        $scan = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $scan);

        // ISO dates in either order, including a date with a sparse day or month.
        $scan = (string) preg_replace('~\b(?:\d{4}-\d{1,2}-\d{1,2}|\d{1,2}-\d{1,2}-\d{4})\b~u', ' ', $scan);

        $months = [
            'january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december',
            'janvier', 'fevrier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'août', 'septembre', 'octobre', 'novembre', 'decembre', 'décembre',
            'يناير', 'فبراير', 'مارس', 'أبريل', 'ابريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'اغسطس', 'سبتمبر', 'أكتوبر', 'اكتوبر', 'نوفمبر', 'ديسمبر',
        ];
        $alternation = implode('|', array_map(static fn (string $month): string => preg_quote($month, '~'), $months));

        // "25 novembre 2026", "November 25, 2026", "25 نوفمبر 2026".
        return (string) preg_replace(
            '~(?<![\p{L}])(?:\d{1,2}\s+(?:'.$alternation.')\s+\d{4}|(?:'.$alternation.')\s+\d{1,2},?\s+\d{4})(?![\p{L}])~iu',
            ' ',
            $scan,
        );
    }

    /** A vague quantity word cannot be proved at all, so its presence ends the check. */
    private function hasVagueQuantity(string $scan, string $locale): bool
    {
        $terms = self::VAGUE_TERMS[$locale] ?? self::VAGUE_TERMS['en'];
        $haystack = $this->normalizeText($scan, $locale);

        foreach ($terms as $term) {
            $needle = $this->normalizeText($term, $locale);
            if ('' === $needle) {
                continue;
            }
            $pattern = '~(?<![\p{L}\p{N}])'.preg_quote($needle, '~').'(?![\p{L}\p{N}])~iu';
            if (1 === preg_match($pattern, $haystack)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every number written in words, as a value and whether it is a percentage.
     *
     * The tokens are numbers, scale words and the language's connector; anything
     * else ends the current number, which is how "twelve customers and three
     * invoices" becomes two claims instead of one.
     *
     * @return list<array{value: int, percent: bool}>
     */
    private function spelledNumbers(string $text, string $locale): array
    {
        $values = self::WORD_VALUES[$locale] ?? self::WORD_VALUES['en'];
        $scales = self::SCALES[$locale] ?? self::SCALES['en'];
        $connectors = self::CONNECTORS[$locale] ?? [];

        $pattern = 'ar' === $locale ? '/[\p{Arabic}\x{0640}]+/u' : '/[\p{L}]+(?:-[\p{L}]+)*/u';
        if (0 === preg_match_all($pattern, $text, $matches, \PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $claims = [];
        $total = 0;
        $current = 0;
        $active = false;
        $end = 0;

        foreach ($matches[0] as [$raw, $offset]) {
            // A hyphenated compound is composed from its parts: quatre-vingt-dix.
            foreach (explode('-', (string) $raw) as $part) {
                $word = $this->normalizeWord($part, $locale);
                if ('' === $word) {
                    continue;
                }
                if ('ar' === $locale && str_starts_with($word, 'و') && mb_strlen($word) > 1) {
                    // Arabic joins "and" to the next word: وعشرون.
                    $stripped = mb_substr($word, 1);
                    if (isset($values[$stripped]) || isset($scales[$stripped])) {
                        $word = $stripped;
                    }
                }

                if (in_array($word, $connectors, true)) {
                    if ($active) {
                        continue;
                    }
                    $this->flush($claims, $total, $current, $active, $text, $end);

                    continue;
                }

                if (isset($scales[$word])) {
                    $scale = $scales[$word];
                    if ($scale >= 1000) {
                        $total += ($active && $current > 0 ? $current : 1) * $scale;
                        $current = 0;
                    } else {
                        $current = ($active && $current > 0 ? $current : 1) * $scale;
                    }
                    $active = true;
                    $end = $offset + strlen((string) $raw);

                    continue;
                }

                if (!isset($values[$word])) {
                    $this->flush($claims, $total, $current, $active, $text, $end);

                    continue;
                }

                $value = $values[$word];
                if ('fr' === $locale && ('un' === $word || 'une' === $word) && !$active) {
                    // "un client" is an article, not the figure 1. Inside a
                    // composition ("vingt et un") it is a number.
                    continue;
                }

                if (!$active) {
                    $current = $value;
                } elseif ('fr' === $locale && $current > 0 && $current < 10 && $value >= 20) {
                    // French multiplies after a small unit: quatre-vingts, six-vingts.
                    // Every multiplier word in the vocabulary (vingt, trente, quarante,
                    // cinquante, soixante) is an exact ten, so no further test is needed.
                    $current *= $value;
                } else {
                    // Additive: vingt-quatre, soixante-dix, quatre-vingt-dix,
                    // خمسة وعشرون, dix-sept.
                    $current += $value;
                }
                $active = true;
                $end = $offset + strlen((string) $raw);
            }
        }

        $this->flush($claims, $total, $current, $active, $text, $end);

        return $claims;
    }

    /**
     * @param list<array{value: int, percent: bool}> $claims
     */
    private function flush(array &$claims, int &$total, int &$current, bool &$active, string $text, int $end): void
    {
        if (!$active) {
            return;
        }

        $claims[] = [
            'value' => $total + $current,
            'percent' => 1 === preg_match(self::PERCENT_WORD_PATTERN, substr($text, $end, 24)),
        ];
        $total = 0;
        $current = 0;
        $active = false;
    }

    /**
     * Lower case, and for Arabic the folding that makes a word recognisable:
     * no diacritics or tatweel, alef/hamza variants folded, ta marbuta to ha.
     */
    private function normalizeWord(string $word, string $locale): string
    {
        $word = mb_strtolower($word);
        if ('ar' !== $locale) {
            return $word;
        }

        $word = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $word) ?? $word;

        return strtr($word, [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
    }

    /** The same folding over a whole phrase, so the vague-word list matches it. */
    private function normalizeText(string $text, string $locale): string
    {
        if ('ar' !== $locale) {
            return mb_strtolower($text);
        }

        $folded = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $text) ?? $text;

        return strtr(mb_strtolower($folded), [
            'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
            'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
        ]);
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
