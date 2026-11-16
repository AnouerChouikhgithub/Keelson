<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use App\Service\InvoiceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Idempotent loader for {@see SyntheticFixtureCatalog}: existing records are
 * matched by natural key and left untouched, missing ones are created. It never
 * deletes or truncates anything, and it refuses any target that is not an
 * obvious local development/test database.
 */
final class SyntheticFixtureLoader
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceCalculator $calculator,
        private readonly SyntheticFixtureCatalog $catalog,
        private readonly FixtureTargetGuard $targetGuard,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $environment,
    ) {
    }

    /**
     * @return array{created: array<string, int>, skipped: array<string, int>, target: string}
     */
    public function load(): array
    {
        $target = $this->assertSafeTarget();
        $definitions = $this->catalog->definitions();
        $stats = ['created' => [], 'skipped' => [], 'target' => $target];
        foreach (array_keys($definitions) as $type) {
            $stats['created'][$type] = 0;
            $stats['skipped'][$type] = 0;
        }

        $principals = [];
        foreach ($definitions['principals'] as $definition) {
            $principal = $this->entityManager->getRepository(Principal::class)
                ->findOneBy(['email' => $definition['email']]);
            if (null === $principal) {
                $principal = new Principal($definition['email'], $definition['displayName']);
                // Documented development password so the seeded accounts can be
                // signed into locally; the target guard keeps this off real databases.
                $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, SyntheticFixtureCatalog::DEV_PASSWORD));
                $principal->markEmailVerified();
                $this->entityManager->persist($principal);
                ++$stats['created']['principals'];
            } else {
                ++$stats['skipped']['principals'];
            }
            $principals[$definition['key']] = $principal;
        }
        $this->entityManager->flush();

        $organizations = [];
        foreach ($definitions['organizations'] as $definition) {
            $organization = $this->entityManager->getRepository(Organization::class)->findOneBy(['subdomain' => $definition['subdomain']]);
            if (null === $organization) {
                $organization = new Organization($definition['name'], $definition['subdomain']);
                $this->entityManager->persist($organization);
                ++$stats['created']['organizations'];
            } else {
                ++$stats['skipped']['organizations'];
            }
            $organizations[$definition['key']] = $organization;
        }
        $this->entityManager->flush();

        foreach ($definitions['memberships'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $principal = $principals[$definition['principal']];
            $exists = null !== $this->entityManager->getRepository(Membership::class)->findOneBy(['organization' => $organization, 'principal' => $principal]);
            if ($exists) {
                ++$stats['skipped']['memberships'];
                continue;
            }
            $this->entityManager->persist(new Membership($organization, $principal, $definition['role']));
            ++$stats['created']['memberships'];
        }
        $this->entityManager->flush();

        $customers = [];
        foreach ($definitions['customers'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $customer = $this->entityManager->getRepository(Customer::class)
                ->findOneBy(['organization' => $organization, 'name' => $definition['name']]);
            if (null === $customer) {
                $customer = new Customer($organization, $definition['name'], $definition['email'], $definition['phone'], $definition['billingAddress']);
                $this->entityManager->persist($customer);
                ++$stats['created']['customers'];
            } else {
                ++$stats['skipped']['customers'];
            }
            $customers[$definition['key']] = $customer;
        }
        $this->entityManager->flush();

        $creatorKey = $definitions['principals'][0]['key'];
        $creatorId = $principals[$creatorKey]->getId();
        foreach ($definitions['invoices'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $exists = $this->entityManager->getRepository(Invoice::class)
                ->findOneBy(['organization' => $organization, 'number' => $definition['number']]);
            if (null !== $exists) {
                ++$stats['skipped']['invoices'];
                continue;
            }
            $calculation = $this->calculator->calculate($definition['lineItems'], $definition['taxRate'], $definition['discountAmount']);
            $today = new \DateTimeImmutable('today');
            $invoice = new Invoice(
                $organization,
                $customers[$definition['customer']],
                $definition['number'],
                $creatorId,
                $today->modify(sprintf('%+d days', $definition['issueOffsetDays'])),
                $today->modify(sprintf('%+d days', $definition['dueOffsetDays'])),
                $calculation['lineItems'],
                $calculation['taxRate'],
                $calculation['discountAmount'],
                $calculation['subtotal'],
                $calculation['taxAmount'],
                $calculation['total'],
            );
            switch ($definition['status']) {
                case 'draft':
                    break;
                case 'sent':
                    $invoice->send();
                    break;
                case 'overdue':
                    $invoice->send();
                    if (!$invoice->markOverdue($today)) {
                        throw new \InvalidArgumentException('An overdue fixture invoice needs a due date before today.');
                    }
                    break;
                case 'paid':
                    $invoice->send();
                    $invoice->markPaid();
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Fixture invoice status "%s" is not supported.', $definition['status']));
            }
            $this->entityManager->persist($invoice);
            ++$stats['created']['invoices'];
        }
        $this->entityManager->flush();

        return $stats;
    }

    /**
     * @return string a credential-free description of the database this loader will write to,
     *                e.g. "keelson_test_test@127.0.0.1"
     */
    public function assertSafeTarget(): string
    {
        // The effective parameters include Doctrine's test-environment
        // `dbname_suffix`; the raw DATABASE_URL does not, so it must not be the
        // thing under inspection.
        return $this->targetGuard->describe(
            $this->entityManager->getConnection()->getParams(),
            $this->environment,
        );
    }
}
