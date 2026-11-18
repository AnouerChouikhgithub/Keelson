<?php

declare(strict_types=1);

namespace App\Tests\Ai\Drills;

use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Repository\OrganizationAiSettingsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two real connections, one nearly empty budget, and a rule that must hold anyway.
 *
 * The drill drives `OrganizationAiSettingsRepository::RESERVE_SQL` — the same constant
 * the application runs, not a copy — from two independent PostgreSQL connections. That
 * is the only way to observe the property: a single-connection test would serialise
 * itself and pass even if the statement were a read-then-write pair.
 *
 * Two things are asserted:
 *
 *  - while the first connection holds the row's lock, a competing reservation waits
 *    rather than proceeding, so it cannot decide against a budget it has not seen;
 *  - once the first commits, the second evaluates the same predicate against the
 *    committed counters and is refused, so a budget with room for one request funds one.
 */
final class BudgetConcurrencyDrillTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Organization $organization;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        // 100 tokens a month, 20 already spent: 80 left, and each drill asks for 60.
        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Budget drill '.$suffix, 'budget-drill-'.$suffix);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist(new OrganizationAiSettings($this->organization, true, 100, new \DateTimeImmutable('2026-01-01 00:00:00')));
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE organization_ai_settings
                SET period = :period, used_tokens = 20, reserved_tokens = 0
              WHERE organization_id = :organizationId',
            [
                'period' => (new \DateTimeImmutable())->format('Y-m'),
                'organizationId' => $this->organization->getId(),
            ],
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM organizations WHERE id = ?',
                [$this->organization->getId()],
            );
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    public function testTheSecondReservationAgainstANearlyEmptyBudgetIsRefused(): void
    {
        $repository = self::getContainer()->get(OrganizationAiSettingsRepository::class);
        self::assertInstanceOf(OrganizationAiSettingsRepository::class, $repository);
        $period = (new \DateTimeImmutable())->format('Y-m');

        self::assertTrue($repository->reserveBudget($this->organization->getId(), $period, 60));
        self::assertFalse($repository->reserveBudget($this->organization->getId(), $period, 60));
        // Exactly 20 tokens are left, and the statement knows it to the token.
        self::assertTrue($repository->reserveBudget($this->organization->getId(), $period, 20));
        self::assertFalse($repository->reserveBudget($this->organization->getId(), $period, 1));
    }

    public function testTwoConnectionsCannotBothReserveTheLastOfTheBudget(): void
    {
        $params = $this->entityManager->getConnection()->getParams();
        unset($params['dbname_suffix']);
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $bind = [
            'period' => (new \DateTimeImmutable())->format('Y-m'),
            'estimate' => 60,
            'now' => new \DateTimeImmutable(),
            'organizationId' => $this->organization->getId(),
        ];
        $types = ['now' => Types::DATETIMETZ_IMMUTABLE];

        try {
            $first->beginTransaction();
            self::assertNotFalse($first->fetchOne(OrganizationAiSettingsRepository::RESERVE_SQL, $bind, $types));

            // The row is locked by the first transaction, so the competitor must wait.
            $second->executeStatement("SET lock_timeout = '300ms'");
            $second->beginTransaction();
            try {
                $second->fetchOne(OrganizationAiSettingsRepository::RESERVE_SQL, $bind, $types);
                self::fail('The competing reservation should wait for the first transaction, not proceed.');
            } catch (\Throwable $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), '55P03') || str_contains($exception->getMessage(), 'lock timeout'),
                    sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
                );
            }
            $second->rollBack();

            $first->commit();

            // The committed reservation is now visible: the predicate re-evaluates and fails.
            $second->beginTransaction();
            self::assertFalse($second->fetchOne(OrganizationAiSettingsRepository::RESERVE_SQL, $bind, $types), 'The budget must not fund both requests.');
            $second->commit();
        } finally {
            $this->rollBackIfActive($first);
            $this->rollBackIfActive($second);
            $first->close();
            $second->close();
        }

        $reserved = (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
        self::assertSame(60, $reserved, 'Only one of the two reservations was ever counted.');
    }

    private function rollBackIfActive(Connection $connection): void
    {
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
    }
}
