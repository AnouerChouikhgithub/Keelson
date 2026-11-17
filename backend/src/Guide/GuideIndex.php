<?php

declare(strict_types=1);

namespace App\Guide;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The product knowledge base, loaded once and searched in memory.
 *
 * Articles are Markdown files with YAML front matter (`id`, `title`, `roles`), one
 * directory per locale. The constructor reads and tokenises every article, so the
 * cost is paid when the service graph is built (the container warmer does it at
 * deploy time, which is also where a malformed article fails the build instead of a
 * request) and a question only costs a scoring pass over a handful of documents.
 *
 * Scoring is BM25 with the usual constants: a term that is rare in the corpus weighs
 * more than one every article contains, and a long article is not rewarded for being
 * long. Below {@see self::MIN_SCORE} the result is empty on purpose — an answer built
 * on a barely related article is worse than "I don't have documentation for that".
 *
 * Only the article body and title are indexed. Front matter never reaches a prompt,
 * and the caller's role is applied as a filter, so an Admin-only article cannot be
 * retrieved for a Viewer and then leak through a source title.
 */
final class GuideIndex
{
    /** A single matched term is around 1.5; anything under this is noise. */
    public const MIN_SCORE = 0.5;

    private const K1 = 1.2;
    private const B = 0.75;
    private const MAXIMUM_TERM_LENGTH = 40;

    /** Precomposed Latin letters are folded explicitly; Arabic keeps its own script. */
    private const LATIN_FOLDING = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a',
        'ç' => 'c', 'ć' => 'c', 'č' => 'c',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ī' => 'i',
        'ñ' => 'n', 'ń' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ū' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /** Arabic orthography varies on these letters, so search treats them as equal. */
    private const ARABIC_FOLDING = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي',
    ];

    private const STOP_WORDS = [
        'en' => [
            'a', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'by', 'can', 'do', 'does', 'for',
            'from', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my', 'of',
            'on', 'or', 'our', 'should', 'that', 'the', 'their', 'them', 'there', 'they', 'this', 'to',
            'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'you',
            'your',
        ],
        'fr' => [
            'a', 'au', 'aux', 'avec', 'ce', 'ces', 'comment', 'dans', 'de', 'des', 'du', 'elle', 'en',
            'est', 'et', 'il', 'je', 'la', 'le', 'les', 'ma', 'mais', 'mon', 'ne', 'nous', 'ou', 'où',
            'par', 'pas', 'pour', 'que', 'quel', 'quelle', 'quels', 'quelles', 'qui', 'sa', 'se', 'ses',
            'son', 'sur', 'un', 'une', 'vos', 'votre', 'vous',
        ],
        'ar' => [
            'إلى', 'أن', 'أو', 'أي', 'إن', 'التي', 'الذي', 'الذين', 'او', 'اي', 'به', 'ثم', 'حتى', 'حول',
            'على', 'عن', 'في', 'قد', 'كيف', 'كم', 'لا', 'لم', 'لن', 'له', 'ما', 'ماذا', 'متى', 'من',
            'هذا', 'هذه', 'هل', 'هو', 'هي', 'و', 'كان', 'كيفية', 'يمكن', 'يجب', 'مع', 'هي',
        ],
    ];

    /** @var array<string, list<GuideDocument>> */
    private array $documents = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/guide')]
        private readonly string $articleDirectory,
    ) {
        $this->load();
    }

    public function count(): int
    {
        return array_sum(array_map(count(...), $this->documents));
    }

    /**
     * The best `$limit` articles for a question, restricted to the caller's role.
     *
     * A locale without a directory falls back to English rather than answering
     * nothing: the article ids are shared, so the client can still label the sources.
     *
     * @return list<array{article: GuideArticle, score: float}>
     */
    public function search(string $question, string $locale, string $role, int $limit = 3): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A search needs room for at least one article.');
        }

        $documents = array_values(array_filter(
            $this->corpus($locale),
            static fn (GuideDocument $document): bool => $document->article->isVisibleTo($role),
        ));
        if ([] === $documents) {
            return [];
        }

        $terms = array_values(array_unique($this->tokenize($question, $locale)));
        if ([] === $terms) {
            return [];
        }

        $total = count($documents);
        $averageLength = max(1.0, array_sum(array_map(static fn (GuideDocument $document): int => $document->length, $documents)) / $total);
        $inverseFrequencies = [];
        foreach ($terms as $term) {
            $matching = 0;
            foreach ($documents as $document) {
                if (isset($document->terms[$term])) {
                    ++$matching;
                }
            }
            $inverseFrequencies[$term] = log(1 + ($total - $matching + 0.5) / ($matching + 0.5));
        }

        $scored = [];
        foreach ($documents as $document) {
            $score = 0.0;
            foreach ($terms as $term) {
                $frequency = $document->terms[$term] ?? 0;
                if (0 === $frequency) {
                    continue;
                }
                $score += $inverseFrequencies[$term] * ($frequency * (self::K1 + 1))
                    / ($frequency + self::K1 * (1 - self::B + self::B * $document->length / $averageLength));
            }
            if ($score >= self::MIN_SCORE) {
                $scored[] = ['article' => $document->article, 'score' => round($score, 4)];
            }
        }

        usort($scored, static fn (array $left, array $right): int => $right['score'] <=> $left['score']
            ?: strcmp($left['article']->id, $right['article']->id));

        return array_slice($scored, 0, $limit);
    }

    /**
     * Lower-cases, folds diacritics, drops stop words and splits on anything that is
     * not a letter or a digit.
     *
     * @return list<string>
     */
    public function tokenize(string $text, string $locale): array
    {
        $folded = mb_strtolower(strtr($text, self::LATIN_FOLDING + self::ARABIC_FOLDING));
        // Combining marks survive the precomposed map; remove them so a decomposed
        // `e` + accent tokenises like a plain `e`.
        $folded = preg_replace('/\p{Mn}+/u', '', $folded) ?? '';
        // Arabic diacritics and the tatweel are decoration, not letters.
        $folded = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}]/u', '', $folded) ?? '';

        $stopWords = self::STOP_WORDS[$locale] ?? self::STOP_WORDS['en'];
        $tokens = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (mb_strlen($token) < 2 || mb_strlen($token) > self::MAXIMUM_TERM_LENGTH) {
                continue;
            }
            if (in_array($token, $stopWords, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /** @return list<GuideDocument> */
    private function corpus(string $locale): array
    {
        return $this->documents[$locale] ?? $this->documents['en'];
    }

    private function load(): void
    {
        $directories = glob($this->articleDirectory.'/*', \GLOB_ONLYDIR) ?: [];
        sort($directories);

        foreach ($directories as $directory) {
            $locale = basename($directory);
            if (!in_array($locale, GuideMessages::LOCALES, true)) {
                continue;
            }
            $files = glob($directory.'/*.md') ?: [];
            sort($files);
            foreach ($files as $file) {
                $this->documents[$locale][] = $this->parse($file, $locale);
            }
        }

        if (!isset($this->documents['en']) || [] === $this->documents['en']) {
            throw new \RuntimeException(sprintf('The knowledge base at "%s" has no English articles.', $this->articleDirectory));
        }
    }

    private function parse(string $file, string $locale): GuideDocument
    {
        $contents = file_get_contents($file);
        if (false === $contents || 1 !== preg_match('/^---\R(.*?)\R---\R(.*)$/s', $contents, $matches)) {
            throw new \RuntimeException(sprintf('The guide article "%s" needs YAML front matter and a body.', $file));
        }

        try {
            $frontMatter = Yaml::parse($matches[1]);
        } catch (ParseException $exception) {
            throw new \RuntimeException(sprintf('The guide article "%s" has invalid front matter.', $file), 0, $exception);
        }
        if (!is_array($frontMatter)) {
            throw new \RuntimeException(sprintf('The guide article "%s" needs a front-matter map.', $file));
        }

        $id = $frontMatter['id'] ?? null;
        $title = $frontMatter['title'] ?? null;
        $roles = $frontMatter['roles'] ?? [];
        $body = trim($matches[2]);

        if (!is_string($id) || 1 !== preg_match('/^[a-z0-9-]{2,64}$/', $id)) {
            throw new \RuntimeException(sprintf('The guide article "%s" needs a lowercase id.', $file));
        }
        if (!is_string($title) || '' === trim($title)) {
            throw new \RuntimeException(sprintf('The guide article "%s" needs a title.', $file));
        }
        if (!is_array($roles)) {
            throw new \RuntimeException(sprintf('The guide article "%s" must list its roles.', $file));
        }
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, ['Owner', 'Admin', 'Member', 'Viewer'], true)) {
                throw new \RuntimeException(sprintf('The guide article "%s" names an unknown role.', $file));
            }
        }
        if ('' === $body) {
            throw new \RuntimeException(sprintf('The guide article "%s" has an empty body.', $file));
        }

        $terms = $this->countTerms($this->tokenize($title.' '.$body, $locale));

        return new GuideDocument(new GuideArticle($id, $title, array_values($roles), $body), $terms, array_sum($terms));
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, int>
     */
    private function countTerms(array $tokens): array
    {
        $terms = [];
        foreach ($tokens as $token) {
            $terms[$token] = ($terms[$token] ?? 0) + 1;
        }

        return $terms;
    }
}
