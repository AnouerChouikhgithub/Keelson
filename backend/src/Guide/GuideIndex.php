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
 * The title, the body and the article's `keywords` are indexed; `keywords` never
 * reaches a prompt and never appears as a source, it only teaches the index how users
 * phrase a question. The caller's role is applied as a filter, so an Admin-only
 * article cannot be retrieved for a Viewer and then leak through a source title.
 */
final class GuideIndex
{
    /** A single matched term is around 1.5; anything under this is noise. */
    public const MIN_SCORE = 0.5;

    /**
     * The number of distinct terms a weak match needs.
     *
     * One ordinary word is not evidence of topicality: "write a poem about the sea"
     * shares "about" with an article and scored 2.4 on it, which was enough to spend a
     * real provider call on a question the corpus cannot answer. A flat two-term rule,
     * however, missed real questions that collapse to one specific word ("How do I invite
     * a teammate?", "What can a Viewer do?"). The rule now accepts one term only when it
     * is specific: present in the article title, or rare enough in the corpus
     * ({@see self::SINGLE_TERM_IDF}). Everything else still needs two.
     */
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
            'a', 'about', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'been', 'by', 'can', 'could', 'do', 'does', 'for',
            'from', 'has', 'have', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'know', 'me', 'my', 'need', 'of',
            'on', 'or', 'our', 'please', 'should', 'someone', 'something', 'tell', 'that', 'the', 'their', 'them', 'there', 'they',
            'this', 'to', 'want', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you',
            'your',
        ],
        'fr' => [
            'a', 'au', 'aux', 'avec', 'ce', 'ces', 'comment', 'dans', 'de', 'des', 'du', 'elle', 'en',
            'est', 'et', 'faire', 'il', 'je', 'la', 'le', 'les', 'ma', 'mais', 'mon', 'ne', 'nous', 'ou', 'où',
            'par', 'pas', 'passe', 'peut', 'pour', 'puis', 'qu', 'que', 'quel', 'quelle', 'quelles', 'quels', 'qui', 'quoi', 'sa', 'se', 'ses',
            'son', 'sont', 'sur', 'un', 'une', 'vos', 'votre', 'vous',
        ],
        'ar' => [
            'إلى', 'أن', 'أو', 'أي', 'إن', 'التي', 'الذي', 'الذين', 'او', 'اي', 'به', 'ثم', 'حتى', 'حول',
            'على', 'عن', 'في', 'قد', 'كيف', 'كم', 'لا', 'لم', 'لن', 'له', 'ما', 'ماذا', 'متى', 'من',
            'هذا', 'هذه', 'هل', 'هو', 'هي', 'و', 'كان', 'كيفية', 'يمكن', 'يجب', 'مع', 'هي',
            'يستطيع', 'تستطيع', 'يفعل', 'تفعل', 'يمكنني', 'استطيع', 'أستطيع', 'اريد', 'أريد',
        ],
    ];

    /**
     * Terms too generic to identify an article on their own. They still count toward the
     * score, but sharing only these ("change my email address") is not a product question.
     *
     * @var array<string, list<string>>
     */
    private const GENERIC_TERMS = [
        'en' => ['account', 'address', 'email', 'mail', 'page', 'screen'],
        'fr' => ['adresse', 'compte', 'courriel', 'ecran', 'mail', 'page'],
        // The doubled alef is intentional: `الإلكتروني` keeps one `ال` after stemming, so a
        // single `الالكتروني` entry is what normalises to the stored term.
        'ar' => ['بريد', 'بريدي', 'كتروني', 'الالكتروني', 'حساب', 'شاشه', 'صفحه', 'عنوان'],
    ];

    /**
     * Requests to produce something the guide does not produce. They are answered locally:
     * "write me an email about the overdue invoice" shares every noun with a real article,
     * so vocabulary alone cannot tell it apart from "how do I mark an invoice paid".
     *
     * @var array<string, list<string>>
     */
    private const OUT_OF_SCOPE_VERBS = [
        'en' => ['book', 'compose', 'draw', 'export', 'import', 'reserve', 'write'],
        'fr' => ['composer', 'dessiner', 'ecri', 'ecrire', 'exporter', 'importer', 'rediger', 'reserver'],
        'ar' => ['احجز', 'ارسم', 'اكتب', 'تصدير', 'حجز', 'صد', 'استيراد'],
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

        // A request to produce something the guide does not produce is answered locally.
        // Its nouns may all be product nouns ("export my invoices to Excel"), so relevance
        // alone cannot tell it from a documentation question.
        // The question's terms are already stemmed (`exporter` is `export`), so the verb
        // list is stemmed the same way before it is compared.
        $outOfScope = array_map(
            fn (string $verb): string => $this->stem($verb, $locale),
            self::OUT_OF_SCOPE_VERBS[$locale] ?? self::OUT_OF_SCOPE_VERBS['en'],
        );
        if ([] !== array_intersect($terms, $outOfScope)) {
            return [];
        }
        // Arabic `أصدّر` (export) and `أصدر` (issue an invoice) collapse to the same
        // letters once the shadda is removed, so export is recognised on the raw text.
        if ('ar' === $locale && 1 === preg_match('/(?:أصدّر|صدّر|تصدير|استيراد)/u', $question)) {
            return [];
        }
        // Compare stemmed forms: `adresse` reaches this point as `adress`, and an
        // unstemmed list would then treat a generic account word as a topic keyword.
        $generic = array_map(fn (string $word): string => $this->stem($word, $locale), self::GENERIC_TERMS[$locale] ?? self::GENERIC_TERMS['en']);

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
            $matched = 0;
            $specific = false;
            foreach ($terms as $term) {
                $frequency = $document->terms[$term] ?? 0;
                if (0 === $frequency) {
                    continue;
                }
                ++$matched;
                $inverseFrequency = $inverseFrequencies[$term];
                $score += $inverseFrequency * ($frequency * (self::K1 + 1))
                    / ($frequency + self::K1 * (1 - self::B + self::B * $document->length / $averageLength));

                // The title is the strongest statement of what an article is about, and a
                // keyword is the corpus author saying "users ask for this with these words".
                $inKeyword = isset($document->keywordTerms[$term]);
                if (isset($document->titleTerms[$term])) {
                    $score += 3 * $inverseFrequency;
                    $specific = true;
                } elseif ($inKeyword) {
                    $score += 2 * $inverseFrequency;
                }
                // A title word or a curated keyword identifies the topic. A generic account
                // word (email, address) does not, and a body word alone never does: "the
                // share price of Apple" must not ride on "price" in the invoice article.
                if (($inKeyword || isset($document->titleTerms[$term])) && !in_array($term, $generic, true)) {
                    $specific = true;
                }
            }
            // An article that answers more of the question is a better answer than one that
            // caught a single synonym: the customer-list question shares two words with
            // `customers` and only the word "parameter" with `ai-settings`.
            $score += 1.0 * max(0, $matched - 1);

            if ($score < self::MIN_SCORE || !$specific) {
                continue;
            }
            $scored[] = ['article' => $document->article, 'score' => round($score, 4)];
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
        // Lower-case first: the folding map has lower-case keys, and `Écris` at the start
        // of a question would otherwise keep its accent and never match `ecrire`.
        $folded = strtr(mb_strtolower($text), self::LATIN_FOLDING + self::ARABIC_FOLDING);
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
            $tokens[] = $this->stem($token, $locale);
        }

        return $tokens;
    }

    /**
     * Light stemming, applied to questions and articles alike so both sides meet.
     *
     * It is deliberately not a full stemmer: the product has three languages and no
     * search dependency, so the rules cover what actually broke retrieval — English
     * plurals and verb endings, French plurals, and the Arabic article, prefixes and
     * broken plurals of the nouns this guide uses. A word it does not recognise stays
     * as it is, which fails safe (a miss) rather than matching the wrong article.
     */
    private function stem(string $token, string $locale): string
    {
        if ('en' === $locale) {
            if (mb_strlen($token) > 4 && str_ends_with($token, 'ies')) {
                return mb_substr($token, 0, -3).'y';
            }
            if (mb_strlen($token) > 4 && preg_match('/(?:ches|shes|sses|xes|zes)$/', $token)) {
                return mb_substr($token, 0, -2);
            }
            if (mb_strlen($token) > 3 && str_ends_with($token, 's') && !preg_match('/(?:ss|us|is)$/', $token)) {
                $token = mb_substr($token, 0, -1);
            }
            if (mb_strlen($token) > 5 && str_ends_with($token, 'ing')) {
                return mb_substr($token, 0, -3);
            }
            if (mb_strlen($token) > 4 && str_ends_with($token, 'ed')) {
                return mb_substr($token, 0, -2);
            }

            return $token;
        }

        if ('fr' === $locale) {
            if (mb_strlen($token) > 4 && preg_match('/[sx]$/', $token)) {
                $token = mb_substr($token, 0, -1);
            }
            // Verb endings, so `rétrograder` in an article and `rétrograde` in a question
            // meet. The rules only shorten; they never invent a different word.
            if (mb_strlen($token) > 5 && str_ends_with($token, 'er')) {
                return mb_substr($token, 0, -2);
            }
            if (mb_strlen($token) > 5 && str_ends_with($token, 'e')) {
                return mb_substr($token, 0, -1);
            }

            return $token;
        }

        return $this->stemArabic($token);
    }

    private function stemArabic(string $token): string
    {
        $brokenPlurals = [
            'فواتير' => 'فاتوره', 'عملاء' => 'عميل', 'اعضاء' => 'عضو', 'دعوات' => 'دعوه',
            'مستخدمين' => 'مستخدم', 'رسائل' => 'رساله',
        ];
        if (isset($brokenPlurals[$token])) {
            return $brokenPlurals[$token];
        }

        // The conjunction is safe to strip; the one-letter prepositions are not, because
        // "فريقي" (my team) and "بريدي" (my mail) begin with the same letters as the
        // prefix. They are stripped only on a longer word, where the root survives.
        if (mb_strlen($token) > 4 && str_starts_with($token, 'و')) {
            $token = mb_substr($token, 1);
        }
        if (mb_strlen($token) > 4 && str_starts_with($token, 'ال')) {
            $token = mb_substr($token, 2);
        }
        // The remaining one-letter prefixes are not stripped. `فاتورة` begins with ف and
        // `لعميل` with ل; removing them produced `اتورة` and broke the noun it was meant to
        // improve. The variants that matter are keywords instead.
        if (mb_strlen($token) > 5 && str_ends_with($token, 'ات')) {
            return mb_substr($token, 0, -2);
        }
        if (mb_strlen($token) > 4 && str_ends_with($token, 'ين')) {
            return mb_substr($token, 0, -2);
        }
        if (mb_strlen($token) > 3 && str_ends_with($token, 'ا')) {
            return mb_substr($token, 0, -1);
        }

        return $token;
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
        $keywords = $frontMatter['keywords'] ?? [];
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
        if (!is_array($keywords)) {
            throw new \RuntimeException(sprintf('The guide article "%s" must list its keywords.', $file));
        }
        foreach ($keywords as $keyword) {
            if (!is_string($keyword) || '' === trim($keyword)) {
                throw new \RuntimeException(sprintf('The guide article "%s" has an empty keyword.', $file));
            }
        }
        /** @var list<string> $keywords */
        $keywords = array_values($keywords);

        $keywordText = implode(' ', $keywords);
        $terms = $this->countTerms($this->tokenize($title.' '.$keywordText.' '.$body, $locale));

        return new GuideDocument(
            new GuideArticle($id, $title, array_values($roles), $body, $keywords),
            $terms,
            array_sum($terms),
            $this->countTerms($this->tokenize($title, $locale)),
            $this->countTerms($this->tokenize($keywordText, $locale)),
        );
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
