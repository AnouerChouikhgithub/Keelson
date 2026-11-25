<?php

declare(strict_types=1);

namespace App\Guide;

/**
 * An article plus the term statistics the index scores it with.
 *
 * `length` is the number of tokens after stop-word removal, which is what BM25
 * normalises by; `terms` maps a token to the number of times it occurs. `titleTerms`
 * and `keywordTerms` are the specificity signal: a question that shares one of them is
 * topically about this article even when it shares only that one word, while one
 * ordinary body word is not enough.
 */
final class GuideDocument
{
    /**
     * @param array<string, int> $terms
     * @param array<string, int> $titleTerms
     * @param array<string, int> $keywordTerms
     */
    public function __construct(
        public readonly GuideArticle $article,
        public readonly array $terms,
        public readonly int $length,
        public readonly array $titleTerms = [],
        public readonly array $keywordTerms = [],
    ) {
    }
}
