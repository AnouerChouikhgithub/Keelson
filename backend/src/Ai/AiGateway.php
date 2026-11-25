<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Exception\AiBudgetExceededException;
use App\Ai\Exception\AiDisabledException;
use App\Ai\Exception\AiRateLimitedException;
use App\Ai\Exception\AiUnavailableException;
use App\Ai\Safety\OutputSanitizer;
use App\Entity\AiRequestRecord;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Repository\MembershipRepository;
use App\Repository\OrganizationAiSettingsRepository;
use App\Service\TransactionBoundary;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The only way a feature reaches a provider.
 *
 * It owns every product rule, in this order: the caller must be an active member of the
 * tenant (otherwise `404`, like every other tenant route), the global switch must be on,
 * the organization must have opted in, both rate-limit buckets must accept the call, and
 * the budget must be able to fund it. Only then is a provider called.
 *
 * Budget ordering is deliberate. The reservation is a single conditional UPDATE that
 * commits before the provider call, so two concurrent requests cannot both be told a
 * nearly empty budget has room for them. The real usage — the token counts the provider
 * reports — replaces the estimate afterwards. A failed call releases the reservation and
 * leaves a `failed` ledger row, so a provider outage neither bills the tenant nor hides
 * that an attempt happened.
 */
final class AiGateway
{
    /** Characters per token is a rough estimate; settlement corrects it with real usage. */
    private const CHARS_PER_TOKEN = 4;

    public function __construct(
        private readonly AiProviderSelector $providers,
        private readonly AiFeatureFlags $flags,
        private readonly OrganizationAiSettingsRepository $settings,
        private readonly MembershipRepository $memberships,
        private readonly EntityManagerInterface $entityManager,
        private readonly TransactionBoundary $transactions,
        private readonly OutputSanitizer $sanitizer,
        private readonly TenantContext $tenants,
        private readonly AiFailureRecorder $failureRecorder,
        #[Autowire(service: 'limiter.ai_principal')]
        private readonly RateLimiterFactoryInterface $principalLimiter,
        #[Autowire(service: 'limiter.ai_organization')]
        private readonly RateLimiterFactoryInterface $organizationLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function complete(Principal $principal, string $organizationId, AiRequest $request): AiResponse
    {
        // The request carries the tenant it was built for, and that must be the tenant
        // this call is billed to: a mismatch would reserve one organization's budget and
        // write the ledger row against another.
        if ($request->organizationId !== $organizationId) {
            throw new \InvalidArgumentException('An AI request must be billed to the organization it was built for.');
        }
        if (null !== $request->principalId && $request->principalId !== $principal->getId()) {
            throw new \InvalidArgumentException('An AI request must carry the principal that asked for it.');
        }

        if (null === $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId())) {
            throw ApiException::notFound();
        }

        if (!$this->flags->isGloballyEnabled()) {
            throw AiDisabledException::create();
        }

        $settings = $this->inTenant(
            $organizationId,
            fn () => $this->settings->findForOrganization($organizationId),
        );
        if (null === $settings || !$settings->isEnabled()) {
            throw AiDisabledException::create();
        }

        $this->consume($this->principalLimiter->create($principal->getId())->consume(1));
        $this->consume($this->organizationLimiter->create($organizationId)->consume(1));

        $period = (new \DateTimeImmutable())->format('Y-m');
        $estimate = $this->estimate($request);
        $reserved = $this->inTenant(
            $organizationId,
            fn (): bool => $this->settings->reserveBudget($organizationId, $period, $estimate),
        );
        if (!$reserved) {
            throw AiBudgetExceededException::create();
        }

        $started = microtime(true);
        try {
            $response = $this->providers->provider()->complete($request);
        } catch (\Throwable $exception) {
            $this->fail($organizationId, $period, $estimate, $request, $started, $this->reason($exception));
            throw $exception instanceof ApiException ? $exception : AiUnavailableException::create(AiUnavailableException::REASON_TRANSPORT);
        }

        $text = $this->sanitizer->sanitize($response->text);
        if ('' === $text) {
            // A provider that answered nothing usable must not look like success. The
            // code says the answer was empty after sanitising, which is distinct from a
            // truncated generation and from a body the parser could not read.
            $this->fail($organizationId, $period, $estimate, $request, $started, AiUnavailableException::REASON_EMPTY_AFTER_SANITIZE);
            throw AiUnavailableException::create(AiUnavailableException::REASON_EMPTY_AFTER_SANITIZE);
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $total = $response->totalTokens();
        $this->inTenant($organizationId, function () use ($organizationId, $period, $estimate, $total, $request, $response, $latencyMs): void {
            $this->settings->settleBudget($organizationId, $period, $estimate, $total);
            $this->entityManager->persist(new AiRequestRecord(
                $organizationId,
                $request->principalId,
                $request->feature,
                AiRequestRecord::STATUS_SUCCEEDED,
                $response->model,
                $response->promptTokens,
                $response->completionTokens,
                $total,
                $latencyMs,
                null,
            ));
            $this->entityManager->flush();
        });

        // No prompt and no answer: the log line is for spend and latency only.
        $this->logger->info('AI request completed', [
            'organizationId' => $organizationId,
            'feature' => $request->feature,
            'tokens' => $total,
            'latencyMs' => $latencyMs,
        ]);

        return new AiResponse($text, $response->promptTokens, $response->completionTokens, $response->model);
    }

    private function fail(
        string $organizationId,
        string $period,
        int $estimate,
        AiRequest $request,
        float $started,
        string $reason,
    ): void {
        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $this->inTenant(
            $organizationId,
            fn () => $this->settings->releaseBudget($organizationId, $period, $estimate),
        );

        // Not through the request's EntityManager: an exception escaping the controller
        // rolls that transaction back and would erase the evidence (see the recorder).
        $this->failureRecorder->record($organizationId, $request->principalId, $request->feature, $latencyMs, $reason);
    }

    /** The reservation must cover the prompt and the answer the caller asked for. */
    private function estimate(AiRequest $request): int
    {
        $characters = mb_strlen($request->systemPrompt) + mb_strlen($request->prompt);

        return max(1, intdiv($characters, self::CHARS_PER_TOKEN) + $request->maxTokens);
    }

    private function consume(RateLimit $limit): void
    {
        if ($limit->isAccepted()) {
            return;
        }

        throw AiRateLimitedException::create($limit->getRetryAfter()->getTimestamp() - time());
    }

    /**
     * Runs one gateway step under the tenant's row-level-security context.
     *
     * `organization_ai_settings` and `ai_requests` are tenant tables, so PostgreSQL
     * filters them by `app.organization_id`. A tenant route has the setting from
     * TenantIsolationListener, but `POST /api/ai/guide` names its organization in the
     * body and has no `{organizationId}` attribute, so the gateway activates the tenant
     * itself. It has already proved the caller is an active member, which is the same
     * condition the listener tests, and `set_config(..., true)` lasts only for the
     * enclosing transaction.
     *
     * @param callable(): mixed $work
     */
    private function inTenant(string $organizationId, callable $work): mixed
    {
        return $this->transactions->run(function () use ($organizationId, $work): mixed {
            $this->tenants->activate($organizationId);

            return $work();
        });
    }

    private function reason(\Throwable $exception): string
    {
        return $exception instanceof AiUnavailableException
            ? $exception->getReason()
            : 'unexpected_provider_error';
    }
}
