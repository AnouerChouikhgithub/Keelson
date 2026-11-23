<?php

declare(strict_types=1);

namespace App\Tests\Ai\Eval;

/**
 * The evaluation data, loaded and validated once per test class.
 *
 * Keeping the questions in JSON rather than in PHP means the same set can be read by the
 * deterministic suite (FakeAiProvider, every CI run) and by the real-provider workflow
 * job, without a second copy that would drift. This class only parses and checks the
 * shape: a question without an expected article, a duplicate id or a locale that is not
 * one of the three the product ships fails here, before any test result is produced.
 *
 * It also refuses to be a silently shrinking eval: the counts below are the contract of
 * the files, so deleting questions to make a run green fails instead.
 */
final class EvalCorpus
{
    /** Locales the guide and the summaries are written in. */
    public const LOCALES = ['en', 'fr', 'ar'];

    /** The eval is 40 covered questions per language plus 10 off-topic ones. */
    public const QUESTIONS_PER_LOCALE = 40;

    public const OFF_TOPIC_PER_LOCALE = 10;

    /**
     * The covered questions for one locale, in file order.
     *
     * @return list<array{id: string, article: string, question: string}>
     */
    public static function guide(string $locale): array
    {
        if (!in_array($locale, self::LOCALES, true)) {
            throw new \InvalidArgumentException(sprintf('No eval corpus for locale "%s".', $locale));
        }

        $data = self::decode('guide-'.$locale.'.json');
        $questions = $data['questions'] ?? null;
        if (!is_array($questions) || self::QUESTIONS_PER_LOCALE !== count($questions)) {
            throw new \RuntimeException(sprintf('guide-%s.json must hold exactly %d questions.', $locale, self::QUESTIONS_PER_LOCALE));
        }

        $seen = [];
        $validated = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                throw new \RuntimeException('Every eval question must be an object.');
            }
            $id = $question['id'] ?? null;
            $article = $question['article'] ?? null;
            $text = $question['question'] ?? null;
            if (!is_string($id) || !is_string($article) || !is_string($text) || '' === trim($text)) {
                throw new \RuntimeException('Every eval question needs an id, an article and a question.');
            }
            if (isset($seen[$id])) {
                throw new \RuntimeException(sprintf('Duplicate eval question id "%s".', $id));
            }
            $seen[$id] = true;
            $validated[] = ['id' => $id, 'article' => $article, 'question' => $text];
        }

        return $validated;
    }

    /**
     * Questions the knowledge base does not cover, all locales unless one is named.
     *
     * @return list<array{id: string, locale: string, question: string}>
     */
    public static function offTopic(?string $locale = null): array
    {
        $questions = self::decode('guide-offtopic.json')['questions'] ?? null;
        if (!is_array($questions)) {
            throw new \RuntimeException('guide-offtopic.json has no questions.');
        }

        $byLocale = [];
        foreach ($questions as $question) {
            if (!is_array($question)) {
                throw new \RuntimeException('Every off-topic question must be an object.');
            }
            $id = $question['id'] ?? null;
            $questionLocale = $question['locale'] ?? null;
            $text = $question['question'] ?? null;
            if (!is_string($id) || !is_string($questionLocale) || !is_string($text) || '' === trim($text)) {
                throw new \RuntimeException('Every off-topic question needs an id, a locale and a question.');
            }
            if (!in_array($questionLocale, self::LOCALES, true)) {
                throw new \RuntimeException(sprintf('Off-topic question "%s" names an unsupported locale.', $id));
            }
            $byLocale[$questionLocale][] = ['id' => $id, 'locale' => $questionLocale, 'question' => $text];
        }

        foreach (self::LOCALES as $supported) {
            if (self::OFF_TOPIC_PER_LOCALE !== count($byLocale[$supported] ?? [])) {
                throw new \RuntimeException(sprintf('guide-offtopic.json needs %d questions for "%s".', self::OFF_TOPIC_PER_LOCALE, $supported));
            }
        }

        if (null === $locale) {
            return array_merge(...array_values($byLocale));
        }
        if (!isset($byLocale[$locale])) {
            throw new \InvalidArgumentException(sprintf('No off-topic questions for locale "%s".', $locale));
        }

        return $byLocale[$locale];
    }

    /** @return array<string, mixed> */
    public static function summaries(): array
    {
        return self::decode('summaries.json');
    }

    /** @return array<string, mixed> */
    private static function decode(string $file): array
    {
        $path = __DIR__.'/data/'.$file;
        $contents = file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException(sprintf('The eval data file "%s" is missing.', $path));
        }

        $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException(sprintf('The eval data file "%s" must hold an object.', $path));
        }

        return $data;
    }
}
