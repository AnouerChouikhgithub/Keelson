<?php

declare(strict_types=1);

namespace App\Guide;

use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Builds the knowledge-base index during `cache:warmup`.
 *
 * Parsing the articles is a startup cost, not a request cost, and a malformed front
 * matter should fail a deployment (or CI) instead of the first question a user asks.
 * The index itself is in memory, so this warm-up proves the files are loadable; the
 * per-request cost is a constructor that reads the same handful of files.
 */
final class GuideIndexWarmer implements CacheWarmerInterface
{
    public function __construct(private readonly GuideIndex $index)
    {
    }

    public function isOptional(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        if ($this->index->count() < 1) {
            throw new \RuntimeException('The knowledge base loaded no articles.');
        }

        return [];
    }
}
