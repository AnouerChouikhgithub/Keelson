<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Repository\OrganizationAiSettingsRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The budget rule has to be decided by the database.
 *
 * The failure this guards against: two requests both read "380 tokens left" and both
 * proceed; the tenant then gets billed past its budget. The fix is one conditional
 * UPDATE ... RETURNING, so PostgreSQL serialises the two writers on the settings row
 * and the loser's predicate is re-evaluated after the first commits. The SQL below is
 * the statement in {@see OrganizationAiSettingsRepository::reserveBudget()}; it is
 * repeated because the point is to drive it from two independent connections.
 */
final class AiBudgetConcurrencyTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Organization $organization;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('AI Budget '.$suffix, 'ai-budget-'.$suffix);
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist(new OrganizationAiSettings($this->organization, true, 100));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $this->entityManager->close();
        }
        parent::tearDown();
    }

    public function testTheSecondSequentialReservationDoesNotFit(): void
    {
        $repository = self::getContainer()->get(OrganizationAiSettingsRepository::class);
        self::assertInstanceOf(OrganizationAiSettingsRepository::class, $repository);
        $period = (new \DateTimeImmutable())->format('Y-m');

        self::assertTrue($repository->reserveBudget($this->organization->getId(), $period, 60));
        self::assertFalse($repository->reserveBudget($this->organization->getId(), $period, 60));
    }

    public function testConcurrentReservationsSerialiseOnTheSettingsRow(): void
    {
        $params = $this->entityManager->getConnection()->getParams();
        $first = DriverManager::getConnection($params);
        $second = DriverManager::getConnection($params);
        $period = (new \DateTimeImmutable())->format('Y-m');
        $bind = [
            'period' => $period,
            'estimate' => 60,
            'now' => new \DateTimeImmutable(),
            'organizationId' => $this->organization->getId(),
        ];
        $types = ['now' => \Doctrine\DBAL\Types\Types::DATETIMETZ_IMMUTABLE];

        try {
            $first->beginTransaction();
            self::assertNotFalse($first->fetchOne($this->sql(), $bind, $types));

            $second->executeStatement("SET lock_timeout = '300ms'");
            $second->beginTransaction();
            try {
                $second->fetchOne($this->sql(), $bind, $types);
                self::fail('The competing reservation should wait for the first transaction, not proceed.');
            } catch (\Throwable $exception) {
                self::assertTrue(
                    str_contains($exception->getMessage(), '55P03') || str_contains($exception->getMessage(), 'lock timeout'),
                    sprintf('Expected a lock-wait timeout, got %s: %s', $exception::class, $exception->getMessage()),
                );
            }
            $second->rollBack();

            $first->commit();

            $second->beginTransaction();
            self::assertFalse($second->fetchOne($this->sql(), $bind, $types), 'The budget must not fund both requests.');
            $second->commit();
        } finally {
            if ($first->isTransactionActive()) {
                $first->rollBack();
            }
            if ($second->isTransactionActive()) {
                $second->rollBack();
            }
            $first->close();
            $second->close();
        }
    }

    private function sql(): string
    {
        return 'UPDATE organization_ai_settings
                SET period = :period,
                    used_tokens = CASE WHEN period = :period THEN used_tokens ELSE 0 END,
                    reserved_tokens = (CASE WHEN period = :period THEN reserved_tokens ELSE 0 END) + :estimate,
                    updated_at = :now
                WHERE organization_id = :organizationId
                  AND enabled = TRUE
                  AND (CASE WHEN period = :period THEN used_tokens ELSE 0 END)
                    + (CASE WHEN period = :period THEN reserved_tokens ELSE 0 END)
                    + :estimate <= monthly_token_budget
                RETURNING id';
    }
}
