<?php

declare(strict_types=1);

namespace App\Summary;

use App\Ai\AiFeatureFlags;
use App\Ai\Exception\AiBudgetExceededException;
use App\Ai\Exception\AiDisabledException;
use App\Ai\Exception\AiUnavailableException;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Repository\OrganizationAiSettingsRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Facts → prose → proof, or the deterministic template.
 *
 * The order is the product rule: resolve the caller's membership and read the
 * facts (the voter already authorized the request), refuse when AI is off, refuse
 * when the month's budget is gone, reuse a cached answer for exactly these facts,
 * otherwise ask the gateway. An answer is only shown when {@see GroundingCheck} can
 * derive every figure in it; a provider outage, an empty answer or an invented
 * number all end in the same place — the template, with `fallback: true` and
 * HTTP 200, because the caller asked for a summary and the facts are still theirs.
 *
 * The cache saves a provider call; it never bypasses a gate. Both refusals are
 * decided before the lookup, so a cached answer cannot outlive an organization's
 * opt-out or its budget (policy B in docs/ai.md), and the guide refuses with the
 * same codes.
 *
 * Budget and rate-limit refusals are not swallowed: they are the contract's 402
 * and 429 and must reach the client.
 */
final class SummaryService
{
    /** Fifteen minutes, or until any fact changes, whichever is first. */
    public const CACHE_TTL = 900;

    public function __construct(
        private readonly WorkspaceFactsBuilder $facts,
        private readonly SummaryTextGenerator $generator,
        private readonly GroundingCheck $grounding,
        private readonly SummaryFallback $fallback,
        private readonly AiFeatureFlags $flags,
        private readonly OrganizationAiSettingsRepository $settings,
        #[Autowire(service: 'cache.ai_summary')]
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{text: string, generatedAt: string, fallback: bool, facts: array<string, mixed>}
     */
    public function summarize(Principal $principal, string $organizationId, SummaryKind $kind, string $locale): array
    {
        $facts = $this->facts->build($organizationId, $principal->getId(), $kind, $locale);
        $settings = $this->assertEnabled($organizationId);
        $this->assertBudgetAvailable($settings);

        $cached = $this->cached($facts);
        if (null !== $cached) {
            return [
                'text' => $cached['text'],
                'generatedAt' => $cached['generatedAt'],
                'fallback' => false,
                'facts' => $facts->toArray(),
            ];
        }

        try {
            $text = $this->generator->generate($principal, $facts);
        } catch (AiUnavailableException) {
            return $this->fallbackResponse($facts);
        }

        $text = trim($text);
        $rejection = '' === $text ? 'empty_answer' : $this->grounding->rejectionReason($text, $facts);
        if (null !== $rejection) {
            // Metadata only: the reason code, the tenant, the locale and the role. The
            // rejected text and the offending token are tenant content and never logged.
            $this->logger->info('AI summary rejected by grounding', [
                'organizationId' => $organizationId,
                'locale' => $facts->locale,
                'role' => $facts->role,
                'reason' => $rejection,
            ]);

            return $this->fallbackResponse($facts);
        }

        $generatedAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $item = $this->cache->getItem($this->cacheKey($facts));
        $item->set(['text' => $text, 'generatedAt' => $generatedAt]);
        $item->expiresAfter(self::CACHE_TTL);
        $this->cache->save($item);

        return [
            'text' => $text,
            'generatedAt' => $generatedAt,
            'fallback' => false,
            'facts' => $facts->toArray(),
        ];
    }

    /**
     * @return array{text: string, generatedAt: string}|null
     */
    private function cached(WorkspaceFacts $facts): ?array
    {
        $item = $this->cache->getItem($this->cacheKey($facts));
        if (!$item->isHit()) {
            return null;
        }

        $cached = $item->get();
        if (!is_array($cached)) {
            return null;
        }

        $text = $cached['text'] ?? null;
        $generatedAt = $cached['generatedAt'] ?? null;
        if (!is_string($text) || '' === $text || !is_string($generatedAt)) {
            return null;
        }

        return ['text' => $text, 'generatedAt' => $generatedAt];
    }

    /**
     * The key the contract asks for: tenant, role, kind and a digest of the facts.
     *
     * The digest is hashed again with the rest so the key is a fixed 72
     * characters with no reserved characters, whatever a tenant id looks like.
     */
    private function cacheKey(WorkspaceFacts $facts): string
    {
        return 'summary.'.hash('sha256', implode('|', [
            $facts->organizationId,
            $facts->role,
            $facts->kind->value,
            $facts->fingerprint(),
        ]));
    }

    /**
     * Refuses before serving a cache entry as well as before a provider call:
     * turning AI off must stop the feature, not just the spend.
     */
    private function assertEnabled(string $organizationId): OrganizationAiSettings
    {
        if (!$this->flags->isGloballyEnabled()) {
            throw AiDisabledException::create();
        }

        $settings = $this->settings->findForOrganization($organizationId);
        if (null === $settings || !$settings->isEnabled()) {
            throw AiDisabledException::create();
        }

        return $settings;
    }

    /**
     * The budget gate covers a cache hit too, because the cache is an economy and
     * not an entitlement.
     *
     * A cached answer costs no provider tokens, but it is model-generated tenant
     * content, and the budget is the tenant's control over the feature. A tenant
     * that set its budget to `0` — the schema default, which is how AI stays off
     * before anyone configures it — or that spent the month's allowance must not
     * keep reading summaries until the entry expires while
     * `POST /api/ai/guide` answers `402`. The check is "any budget left", not the
     * provider estimate: a cache hit consumes nothing, and the gateway keeps its
     * own reservation rule for the call it actually makes.
     */
    private function assertBudgetAvailable(OrganizationAiSettings $settings): void
    {
        if (0 === $settings->remainingTokens((new \DateTimeImmutable())->format('Y-m'))) {
            throw AiBudgetExceededException::create();
        }
    }

    /**
     * @return array{text: string, generatedAt: string, fallback: bool, facts: array<string, mixed>}
     */
    private function fallbackResponse(WorkspaceFacts $facts): array
    {
        return [
            'text' => $this->fallback->text($facts),
            'generatedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'fallback' => true,
            'facts' => $facts->toArray(),
        ];
    }
}
