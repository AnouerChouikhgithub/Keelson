<?php

declare(strict_types=1);

namespace App\Guide;

/**
 * An article plus the term statistics the index scores it with.
 *
 * `length` is the number of tokens after stop-word removal, which is what BM25
 * normalises by; `terms` maps a token to the number of times it occurs.
 */
final class GuideDocument
{
    /**
     * @param array<string, int> $terms
     */
    public function __construct(
        public readonly GuideArticle $article,
        public readonly array $terms,
        public readonly int $length,
    ) {
    }
}
