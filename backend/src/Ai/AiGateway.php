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
        #[Autowire(service: 'limiter.ai_principal')]
        private readonly RateLimiterFactoryInterface $principalLimiter,
        #[Autowire(service: 'limiter.ai_organization')]
        private readonly RateLimiterFactoryInterface $organizationLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function complete(Principal $principal, string $organizationId, AiRequest $request): AiResponse
    {
        if (null === $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId())) {
            throw ApiException::notFound();
        }

        if (!$this->flags->isGloballyEnabled()) {
            throw AiDisabledException::create();
        }

        $settings = $this->settings->findForOrganization($organizationId);
        if (null === $settings || !$settings->isEnabled()) {
            throw AiDisabledException::create();
        }

        $this->consume($this->principalLimiter->create($principal->getId())->consume(1));
        $this->consume($this->organizationLimiter->create($organizationId)->consume(1));

        $period = (new \DateTimeImmutable())->format('Y-m');
        $estimate = $this->estimate($request);
        $reserved = $this->transactions->run(
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
            // A provider that answered nothing usable must not look like success.
            $this->fail($organizationId, $period, $estimate, $request, $started, AiUnavailableException::REASON_MALFORMED);
            throw AiUnavailableException::create(AiUnavailableException::REASON_MALFORMED);
        }

        $latencyMs = (int) round((microtime(true) - $started) * 1000);
        $total = $response->totalTokens();
        $this->transactions->run(function () use ($organizationId, $period, $estimate, $total, $request, $response, $latencyMs): void {
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
        $this->transactions->run(function () use ($organizationId, $period, $estimate, $request, $latencyMs, $reason): void {
            $this->settings->releaseBudget($organizationId, $period, $estimate);
            $this->entityManager->persist(new AiRequestRecord(
                $organizationId,
                $request->principalId,
                $request->feature,
                AiRequestRecord::STATUS_FAILED,
                null,
                0,
                0,
                0,
                $latencyMs,
                substr($reason, 0, 64),
            ));
            $this->entityManager->flush();
        });
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

    private function reason(\Throwable $exception): string
    {
        return $exception instanceof AiUnavailableException
            ? $exception->getReason()
            : 'unexpected_provider_error';
    }
}
