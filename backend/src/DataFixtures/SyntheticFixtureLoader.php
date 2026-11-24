<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Audit\InvoiceAuditAction;
use App\Entity\Customer;
use App\Entity\Invitation;
use App\Entity\Invoice;
use App\Entity\Membership;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Service\InvoiceAuditLogger;
use App\Service\InvoiceCalculator;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Idempotent loader for {@see SyntheticFixtureCatalog}: existing records are
 * matched by natural key and left untouched, missing ones are created. It never
 * deletes or truncates anything, and it refuses any target that is not an
 * obvious local development/test database.
 *
 * Accounts can sign in: the shared password is hashed here, once per created
 * account, with the application's own hasher. The plaintext comes from
 * FIXTURE_PASSWORD (or the documented fallback) and the hash never leaves the
 * entity — nothing in the result contains it.
 */
final class SyntheticFixtureLoader
{
    private const WRITABLE_ROLES = ['Owner', 'Admin', 'Member'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceCalculator $calculator,
        private readonly SyntheticFixtureCatalog $catalog,
        private readonly FixtureTargetGuard $targetGuard,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly InvoiceAuditLogger $audits,
        private readonly TenantContext $tenants,
        private readonly string $environment,
        private readonly ?string $password = null,
    ) {
    }

    /**
     * @return array{
     *     created: array<string, int>,
     *     skipped: array<string, int>,
     *     target: string,
     *     password: string,
     *     accounts: list<array{email: string, displayName: string, roles: list<string>, organizations: list<string>, verified: bool}>
     * }
     */
    public function load(): array
    {
        $target = $this->assertSafeTarget();
        $password = $this->demoPassword();
        $definitions = $this->catalog->definitions();
        $stats = ['created' => [], 'skipped' => [], 'target' => $target, 'password' => $password, 'accounts' => []];
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
                // The documented demo password, hashed with the application's
                // hasher at seed time. No hash is ever written to disk or output.
                $principal->setPasswordHash($this->passwordHasher->hashPassword($principal, $password));
                if ($definition['verified']) {
                    $principal->markEmailVerified();
                }
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

        $rolesByPrincipal = [];
        $organizationsByPrincipal = [];
        $actorKeys = [];
        foreach ($definitions['memberships'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $principal = $principals[$definition['principal']];
            $rolesByPrincipal[$definition['principal']][] = $definition['role'];
            $organizationsByPrincipal[$definition['principal']][] = $organization->getName();
            // Invoices are attributed to the first member who could have written
            // them, so a seeded audit trail never names a Viewer as its author.
            $actorKeys[$definition['organization']] ??= in_array($definition['role'], self::WRITABLE_ROLES, true)
                ? $definition['principal']
                : null;
            $exists = null !== $this->entityManager->getRepository(Membership::class)->findOneBy(['organization' => $organization, 'principal' => $principal]);
            if ($exists) {
                ++$stats['skipped']['memberships'];
                continue;
            }
            $this->entityManager->persist(new Membership($organization, $principal, $definition['role']));
            ++$stats['created']['memberships'];
        }
        $this->entityManager->flush();

        foreach ($definitions['ai_settings'] as $definition) {
            $organization = $organizations[$definition['organization']];
            // Forced row-level security covers this table too, so the row is
            // written inside the tenant context like every other tenant table.
            $created = $this->tenants->run($organization->getId(), function () use ($definition, $organization): bool {
                $exists = $this->entityManager->getRepository(OrganizationAiSettings::class)
                    ->findOneBy(['organization' => $organization]);
                if (null !== $exists) {
                    return false;
                }
                $this->entityManager->persist(new OrganizationAiSettings(
                    $organization,
                    $definition['enabled'],
                    $definition['monthlyTokenBudget'],
                ));
                $this->entityManager->flush();

                return true;
            });
            ++$stats[$created ? 'created' : 'skipped']['ai_settings'];
        }

        $customers = [];
        foreach ($definitions['customers'] as $definition) {
            $organization = $organizations[$definition['organization']];
            // Row-level security is part of the schema, so the seeder states which
            // tenant every row belongs to instead of relying on a privileged role.
            $customers[$definition['key']] = $this->tenants->run($organization->getId(), function () use ($definition, $organization, &$stats): Customer {
                $customer = $this->entityManager->getRepository(Customer::class)
                    ->findOneBy(['organization' => $organization, 'name' => $definition['name']]);
                if (null === $customer) {
                    $customer = new Customer($organization, $definition['name'], $definition['email'], $definition['phone'], $definition['billingAddress']);
                    $this->entityManager->persist($customer);
                    ++$stats['created']['customers'];
                } else {
                    ++$stats['skipped']['customers'];
                }
                $this->entityManager->flush();

                return $customer;
            });
        }

        $today = new \DateTimeImmutable('today');
        foreach ($definitions['invoices'] as $definition) {
            $organizationKey = $definition['organization'];
            $organization = $organizations[$organizationKey];
            $created = $this->tenants->run($organization->getId(), function () use ($definition, $organization, $organizationKey, $principals, $actorKeys, $customers, $today): bool {
                $exists = $this->entityManager->getRepository(Invoice::class)
                    ->findOneBy(['organization' => $organization, 'number' => $definition['number']]);
                if (null !== $exists) {
                    return false;
                }
                $actor = $principals[$actorKeys[$organizationKey]];
                $calculation = $this->calculator->calculate($definition['lineItems'], $definition['taxRate'], $definition['discountAmount']);
                $issuedAt = $today->modify(sprintf('%+d days', $definition['issueOffsetDays']));
                $invoice = new Invoice(
                    $organization,
                    $customers[$definition['customer']],
                    $definition['number'],
                    $actor->getId(),
                    $issuedAt,
                    $today->modify(sprintf('%+d days', $definition['dueOffsetDays'])),
                    $calculation['lineItems'],
                    $calculation['taxRate'],
                    $calculation['discountAmount'],
                    $calculation['subtotal'],
                    $calculation['taxAmount'],
                    $calculation['total'],
                    // Created, sent and paid dates follow the invoice's own timeline
                    // instead of all landing on the seeding second.
                    $issuedAt,
                );
                $this->entityManager->persist($invoice);
                // Same service the API uses for every mutation, so the seeded trail
                // is indistinguishable in shape from a real one.
                $this->audits->record($organization, $actor, InvoiceAuditAction::INVOICE_CREATED, $invoice->getId(), $customers[$definition['customer']]->getId(), [], $issuedAt);
                switch ($definition['status']) {
                    case 'draft':
                        break;
                    case 'sent':
                        $invoice->send($issuedAt);
                        $this->audits->record($organization, $actor, InvoiceAuditAction::INVOICE_SENT, $invoice->getId(), null, [], $issuedAt);
                        break;
                    case 'overdue':
                        $invoice->send($issuedAt);
                        $this->audits->record($organization, $actor, InvoiceAuditAction::INVOICE_SENT, $invoice->getId(), null, [], $issuedAt);
                        if (!$invoice->markOverdue($today)) {
                            throw new \InvalidArgumentException('An overdue fixture invoice needs a due date before today.');
                        }
                        break;
                    case 'paid':
                        $invoice->send($issuedAt);
                        $this->audits->record($organization, $actor, InvoiceAuditAction::INVOICE_SENT, $invoice->getId(), null, [], $issuedAt);
                        $paidAt = $invoice->getDueDate()->modify('-2 days');
                        $invoice->markPaid($paidAt);
                        $this->audits->record($organization, $actor, InvoiceAuditAction::INVOICE_PAID, $invoice->getId(), null, [], $paidAt);
                        break;
                    default:
                        throw new \InvalidArgumentException(sprintf('Fixture invoice status "%s" is not supported.', $definition['status']));
                }
                $this->entityManager->flush();

                return true;
            });
            ++$stats[$created ? 'created' : 'skipped']['invoices'];
        }

        foreach ($definitions['invitations'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $created = $this->tenants->run($organization->getId(), function () use ($definition, $organization, $principals): bool {
                $exists = $this->entityManager->getRepository(Invitation::class)
                    ->findOneBy(['organization' => $organization, 'email' => $definition['email']]);
                if (null !== $exists) {
                    return false;
                }
                // A known token makes the public accept link usable locally; the row
                // still stores only its digest, exactly like a real invitation.
                $token = 'fixture-invitation-'.$definition['key'];
                $invitation = new Invitation(
                    $organization,
                    $definition['email'],
                    $definition['role'],
                    hash('sha256', $token),
                    $principals[$definition['invitedBy']],
                    (new \DateTimeImmutable())->modify(sprintf('%+d days', $definition['expiresInDays'])),
                );
                $this->entityManager->persist($invitation);
                $this->entityManager->flush();

                return true;
            });
            ++$stats[$created ? 'created' : 'skipped']['invitations'];
        }

        // The expired invitation is created inside its window (the entity refuses
        // a past expiry) and then aged by the loader, which is the only way to
        // seed a state that otherwise takes a week to reach.
        foreach ($definitions['invitations'] as $definition) {
            if (!$definition['expired']) {
                continue;
            }
            $organization = $organizations[$definition['organization']];
            $this->tenants->run($organization->getId(), function () use ($organization, $definition): void {
                $this->entityManager->getConnection()->executeStatement(
                    'UPDATE invitations SET expires_at = now() - interval \'3 days\' WHERE organization_id = ? AND email = ? AND expires_at > now()',
                    [$organization->getId(), $definition['email']],
                );
            });
        }

        foreach ($definitions['notifications'] as $definition) {
            $organization = $organizations[$definition['organization']];
            $principal = $principals[$definition['principal']];
            $created = $this->tenants->run($organization->getId(), function () use ($definition, $organization, $principal): bool {
                // `fixtureKey` is the natural key: the loader can tell its own rows
                // apart from anything the application created, without a schema
                // column that exists only for fixtures.
                $exists = $this->entityManager->getConnection()->fetchOne(
                    "SELECT 1 FROM notifications WHERE principal_id = ? AND data->>'fixtureKey' = ? LIMIT 1",
                    [$principal->getId(), $definition['key']],
                );
                if (false !== $exists) {
                    return false;
                }
                $createdAt = (new \DateTimeImmutable())->modify(sprintf('-%d days', $definition['daysAgo']))->setTime(9, 30);
                $notification = new Notification($principal, $organization, $definition['type'], [
                    'fixtureKey' => $definition['key'],
                    'organizationName' => $organization->getName(),
                ], $createdAt);
                if ($definition['read']) {
                    $notification->markRead($createdAt->modify('+2 hours'));
                }
                $this->entityManager->persist($notification);
                $this->entityManager->flush();

                return true;
            });
            ++$stats[$created ? 'created' : 'skipped']['notifications'];
        }

        foreach ($definitions['principals'] as $definition) {
            $stats['accounts'][] = [
                'email' => $principals[$definition['key']]->getEmail(),
                'displayName' => (string) $principals[$definition['key']]->getDisplayName(),
                // One row per person: a member of two organizations with the
                // same role should read "Member", not "Member, Member".
                'roles' => array_values(array_unique($rolesByPrincipal[$definition['key']] ?? [])),
                'organizations' => $organizationsByPrincipal[$definition['key']] ?? [],
                'verified' => $definition['verified'],
            ];
        }

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

    /**
     * The plaintext password applied to every demo account.
     *
     * It is only needed in memory: to hash it for a new account, and for the
     * command's account table. It is validated before anything is written, so a
     * too-short FIXTURE_PASSWORD cannot produce accounts that registration would
     * have refused.
     */
    public function demoPassword(): string
    {
        $password = trim((string) $this->password);
        if ('' === $password) {
            return SyntheticFixtureCatalog::FALLBACK_PASSWORD;
        }
        if (mb_strlen($password) < SyntheticFixtureCatalog::MINIMUM_PASSWORD_LENGTH) {
            throw new \RuntimeException(sprintf('FIXTURE_PASSWORD must be at least %d characters long.', SyntheticFixtureCatalog::MINIMUM_PASSWORD_LENGTH));
        }

        return $password;
    }
}
