<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Ai\AiBudgetPolicy;
use App\Entity\AiRequestRecord;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:ai:usage` must report the ledger the budget is enforced from.
 *
 * The numbers asserted here are the same ones `ai_requests` holds: per UTC day, with
 * prompt and completion tokens kept apart and failures counted. The command runs inside
 * each organization's tenant context, which is what makes it work against a
 * row-level-security-protected table from a console (no request transaction to inherit).
 *
 * The JSON form is asserted exactly, and the table form is asserted for the warning it
 * has to print when a day crosses the documented alert threshold.
 */
final class AiUsageCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Organization $organization;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Usage '.$suffix, 'usage-'.$suffix);
        $this->principal = new Principal('usage-'.$suffix.'@example.test', 'Usage Owner');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new OrganizationAiSettings($this->organization, true, 100000));
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testItReportsTokensPerOrganizationPerDay(): void
    {
        $today = new \DateTimeImmutable('today 09:00');
        $yesterday = $today->modify('-1 day');
        $this->record($today, 'succeeded', 120, 80);
        $this->record($today, 'failed', 0, 0);
        $this->record($yesterday, 'succeeded', 300, 200);
        $this->entityManager->flush();

        $report = $this->runCommand(['--organization' => $this->organization->getId(), '--days' => '3', '--json' => true]);

        self::assertCount(1, $report);
        self::assertSame($this->organization->getId(), $report[0]['organizationId']);
        self::assertCount(2, $report[0]['days']);

        self::assertSame($yesterday->format('Y-m-d'), $report[0]['days'][0]['day']);
        self::assertSame(1, $report[0]['days'][0]['requests']);
        self::assertSame(0, $report[0]['days'][0]['failed']);
        self::assertSame(300, $report[0]['days'][0]['promptTokens']);
        self::assertSame(200, $report[0]['days'][0]['completionTokens']);
        self::assertSame(500, $report[0]['days'][0]['totalTokens']);

        self::assertSame($today->format('Y-m-d'), $report[0]['days'][1]['day']);
        self::assertSame(2, $report[0]['days'][1]['requests'], 'A failed attempt is a request in the ledger.');
        self::assertSame(1, $report[0]['days'][1]['failed']);
        self::assertSame(200, $report[0]['days'][1]['totalTokens']);
        self::assertSame(700, $report[0]['windowTokens']);
    }

    public function testItPrintsTheMonthlyBudgetStateAndTheSpikeWarning(): void
    {
        $today = new \DateTimeImmutable('today 09:00');
        $this->record($today, 'succeeded', AiBudgetPolicy::ALERT_DAILY_TOKENS + 1000, 0);
        $this->entityManager->flush();

        $display = $this->runCommand(['--organization' => $this->organization->getId(), '--days' => '2']);

        self::assertStringContainsString('AI enabled', $display);
        self::assertStringContainsString(number_format(100000), $display);
        self::assertStringContainsString($today->format('Y-m-d'), $display);
        self::assertStringContainsString(
            sprintf('Daily usage above the %s-token alert threshold', number_format(AiBudgetPolicy::ALERT_DAILY_TOKENS)),
            $display,
        );
    }

    public function testItRefusesAMalformedOrganizationId(): void
    {
        $tester = $this->tester(['--organization' => 'not-a-uuid']);

        // 2 is Symfony's Command::INVALID: a usage error, not a report.
        self::assertSame(2, $tester->getStatusCode(), 'A malformed id is a usage error, not a report.');
        self::assertStringContainsString('--organization must be a UUID', $tester->getDisplay());
    }

    public function testAnOrganizationWithoutUsageIsReportedAsSuch(): void
    {
        $display = $this->runCommand(['--organization' => $this->organization->getId()]);

        self::assertStringContainsString('No AI request was recorded in this window', $display);
    }

    /**
     * @param array<string, string|bool> $arguments
     *
     * @return array<int, array<string, mixed>>|string
     */
    private function runCommand(array $arguments): array|string
    {
        $tester = $this->tester($arguments);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        if (true === ($arguments['--json'] ?? false)) {
            $decoded = json_decode($display, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);

            return $decoded;
        }

        return $display;
    }

    /**
     * @param array<string, string|bool> $arguments
     */
    private function tester(array $arguments): CommandTester
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('app:ai:usage'));
        $tester->execute($arguments);

        return $tester;
    }

    private function record(\DateTimeImmutable $at, string $status, int $promptTokens, int $completionTokens): void
    {
        $this->entityManager->persist(new AiRequestRecord(
            $this->organization->getId(),
            $this->principal->getId(),
            'guide',
            $status,
            'fake-model',
            $promptTokens,
            $completionTokens,
            $promptTokens + $completionTokens,
            120,
            'failed' === $status ? 'timeout' : null,
            $at,
        ));
    }
}
