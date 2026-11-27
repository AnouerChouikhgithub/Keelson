<?php

declare(strict_types=1);

namespace App\Tests\Ai\Drills;

use App\Ai\AiFeatureFlags;
use App\Ai\AiGateway;
use App\Ai\AiRequest;
use App\Ai\Exception\AiUnavailableException;
use App\Ai\FakeAiProvider;
use App\Ai\Http\AiHttpResponse;
use App\Ai\Http\AiHttpTransport;
use App\Ai\HttpAiProvider;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Tests\Support\ScriptedAiTransport;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What happens when the provider does not cooperate, and what the caller and the ledger
 * see afterwards.
 *
 * Each drill asserts four things at once, because any one of them alone would hide a
 * regression: the status and error code the client gets, the reason recorded in the
 * ledger, the reservation being released, and the tenant not being billed. A provider
 * outage is the case where all four are easy to get wrong together — the exception path
 * must roll the reservation back *and* keep the evidence that an attempt happened.
 *
 * The provider-side failures use `FakeAiProvider::failWith()`, which is why it exists.
 * The HTTP classification (a 429 is not retried, a 5xx is retried once) is asserted
 * against a scripted transport, since that is the layer that decides it.
 */
final class ProviderFailureDrillTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private AiGateway $gateway;
    private AiFeatureFlags $flags;
    private FakeAiProvider $fake;
    private Organization $organization;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $gateway = $container->get(AiGateway::class);
        self::assertInstanceOf(AiGateway::class, $gateway);
        $this->gateway = $gateway;

        $flags = $container->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $fake = $container->get(FakeAiProvider::class);
        self::assertInstanceOf(FakeAiProvider::class, $fake);
        $this->fake = $fake;
        $this->fake->failWith(FakeAiProvider::FAILURE_NONE);

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Drill '.$suffix, 'drill-'.$suffix);
        $this->principal = new Principal('drill-'.$suffix.'@example.test', 'Drill Owner');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->persist(new OrganizationAiSettings($this->organization, true, 1000000));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);
        $this->fake?->failWith(FakeAiProvider::FAILURE_NONE);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function providerFailures(): iterable
    {
        yield 'timeout' => [FakeAiProvider::FAILURE_TIMEOUT, 'timeout'];
        yield 'server error' => [FakeAiProvider::FAILURE_SERVER_ERROR, 'server_error'];
        yield 'malformed json' => [FakeAiProvider::FAILURE_MALFORMED, 'malformed_body'];
        yield 'empty answer' => [FakeAiProvider::FAILURE_EMPTY, 'empty_after_sanitize'];
    }

    #[DataProvider('providerFailures')]
    public function testAFailedCallIsUnavailableReleasedAndRecorded(string $failure, string $reason): void
    {
        $this->fake->failWith($failure);

        $exception = $this->refusal();
        self::assertSame('ai_unavailable', $exception->getErrorCode());
        self::assertSame(503, $exception->getStatusCode());

        $rows = $this->ledger();
        self::assertCount(1, $rows, 'A failed attempt is still evidence and gets a row.');
        self::assertSame('failed', $rows[0]['status']);
        self::assertSame($reason, $rows[0]['error_code']);
        self::assertSame(0, (int) $rows[0]['total_tokens']);

        self::assertSame(0, $this->reservedTokens(), 'The reservation is released.');
        self::assertSame(0, $this->usedTokens(), 'A failure never bills the tenant.');
    }

    /** The global switch stops the call before any budget or provider work. */
    public function testTheGlobalSwitchStopsEverything(): void
    {
        $this->flags->overrideGlobalEnabledForTests(false);

        $exception = $this->refusal();
        self::assertSame('ai_disabled', $exception->getErrorCode());
        self::assertSame(403, $exception->getStatusCode());
        self::assertSame([], $this->ledger());
        self::assertSame(0, $this->reservedTokens());
        self::assertSame(0, $this->usedTokens());
    }

    /** A request built for another tenant is refused before anything is reserved. */
    public function testARequestBuiltForAnotherTenantIsRefused(): void
    {
        $other = new Organization('Drill other '.bin2hex(random_bytes(6)), 'drill-other-'.bin2hex(random_bytes(6)));
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        try {
            $this->gateway->complete($this->principal, $this->organization->getId(), new AiRequest(
                'guide',
                'You are a helpful assistant.',
                'How do I send an invoice?',
                50,
                $other->getId(),
                $this->principal->getId(),
            ));
            self::fail('A request whose tenant differs from the billed tenant must not run.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('billed to the organization', $exception->getMessage());
        } finally {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM organizations WHERE id = ?', [$other->getId()]);
        }

        self::assertSame([], $this->ledger());
        self::assertSame(0, $this->reservedTokens());
    }

    /** A request that names somebody else as its principal is refused the same way. */
    public function testARequestNamingAnotherPrincipalIsRefused(): void
    {
        $stranger = new Principal('drill-stranger-'.bin2hex(random_bytes(6)).'@example.test', 'Stranger');
        $this->entityManager->persist($stranger);
        $this->entityManager->flush();

        try {
            $this->gateway->complete($this->principal, $this->organization->getId(), new AiRequest(
                'guide',
                'You are a helpful assistant.',
                'How do I send an invoice?',
                50,
                $this->organization->getId(),
                $stranger->getId(),
            ));
            self::fail('A ledger row must name the principal that asked.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('the principal that asked', $exception->getMessage());
        } finally {
            $this->entityManager->getConnection()->executeStatement('DELETE FROM principals WHERE id = ?', [$stranger->getId()]);
        }

        self::assertSame([], $this->ledger());
    }

    /** A provider 429 is a transport failure, and is not retried: the attempt is not free. */
    public function testAProviderRateLimitIsNotRetried(): void
    {
        $transport = new ScriptedAiTransport([new AiHttpResponse(429, '{"error":"rate limited"}')]);

        try {
            $this->httpProvider($transport)->complete($this->request());
            self::fail('A 429 must not become an answer.');
        } catch (AiUnavailableException $exception) {
            self::assertSame('ai_unavailable', $exception->getErrorCode());
            self::assertSame(AiUnavailableException::REASON_TRANSPORT, $exception->getReason());
        }

        self::assertCount(1, $transport->requests, 'Only a 5xx is retried.');
    }

    /** A 500 is retried once; a second one is the answer. */
    public function testAProviderServerErrorIsRetriedOnceThenRefused(): void
    {
        $transport = new ScriptedAiTransport([
            new AiHttpResponse(500, 'boom'),
            new AiHttpResponse(500, 'boom again'),
        ]);

        try {
            $this->httpProvider($transport)->complete($this->request());
            self::fail('Two 5xx answers must not produce an answer.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_SERVER_ERROR, $exception->getReason());
        }

        self::assertCount(2, $transport->requests);
    }

    /** A 200 with a body that is not the expected JSON is malformed, not an answer. */
    public function testAMalformedProviderBodyIsRefused(): void
    {
        $transport = new ScriptedAiTransport([new AiHttpResponse(200, 'not json at all')]);

        try {
            $this->httpProvider($transport)->complete($this->request());
            self::fail('A body that does not parse must not become an answer.');
        } catch (AiUnavailableException $exception) {
            self::assertSame(AiUnavailableException::REASON_MALFORMED_BODY, $exception->getReason());
        }
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            'guide',
            'You are a helpful assistant.',
            'How do I send an invoice?',
            50,
            $this->organization->getId(),
            $this->principal->getId(),
        );
    }

    private function refusal(): ApiException
    {
        try {
            $this->gateway->complete($this->principal, $this->organization->getId(), $this->request());
            self::fail('The gateway should have refused this request.');
        } catch (ApiException $exception) {
            return $exception;
        }
    }

    private function httpProvider(AiHttpTransport $transport): HttpAiProvider
    {
        return new HttpAiProvider($transport, 'test-key', 'test-model', 'https://example.test/v1');
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT status, feature, error_code, total_tokens FROM ai_requests WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function reservedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function usedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT used_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }
}
