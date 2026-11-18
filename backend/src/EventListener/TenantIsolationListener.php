<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Principal;
use App\Repository\MembershipRepository;
use App\Tenancy\TenantContext;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Binds the request to its tenant for as long as its transaction lasts.
 *
 * Row-level security needs `app.organization_id` set on the connection before the
 * first tenant read. That cannot be a session-level setting: php-fpm reuses the
 * connection for other users' requests, so a value left behind is a cross-tenant
 * leak. It therefore has to be transaction-local, and a transaction-local setting
 * needs a transaction around the request — which is what this subscriber opens.
 *
 * Ordering matters. `KernelEvents::REQUEST` at priority 0 runs after the firewall
 * (8) and after the access listener (1) that consults the voters, so an
 * unauthenticated or denied request never opens a transaction, and a controller
 * always runs inside one.
 *
 * The cost is real and deliberate: every authenticated request holds one database
 * transaction from routing until the response is built. A slow controller holds a
 * connection (and a snapshot) for its whole duration, exactly as an explicit
 * `BEGIN` in the controller would. Reads are not otherwise affected, and the
 * controllers' own TransactionBoundary calls join this transaction instead of
 * opening a second one, so the write path is unchanged.
 */
final class TenantIsolationListener implements EventSubscriberInterface
{
    /** True only when this listener opened the transaction it is holding. */
    private bool $ownsTransaction = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MembershipRepository $memberships,
        private readonly TenantContext $tenants,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall and the access listener (see the class note).
            KernelEvents::REQUEST => ['onRequest', 0],
            // Before anything that turns the failure into a response, so no
            // partially applied unit of work can be committed on the way out.
            KernelEvents::EXCEPTION => ['onException', 512],
            // Before the session is written (-1000): a commit failure must fail
            // the request rather than be reported as a successful one.
            KernelEvents::RESPONSE => ['onResponse', -128],
            KernelEvents::TERMINATE => ['onTerminate', -128],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        // A fragment or a forwarded request shares the master request's work.
        if (!$event->isMainRequest()) {
            return;
        }

        $principal = $this->tokenStorage->getToken()?->getUser();
        if (!$principal instanceof Principal) {
            return;
        }

        $organizationId = $event->getRequest()->attributes->get('organizationId');
        $organizationId = is_string($organizationId) && Uuid::isValid($organizationId) ? $organizationId : null;

        // A tenant route is only scoped for a caller with an active membership in
        // that organization. Without one the request still needs the principal
        // context (the notification inbox), but it must not claim the tenant: the
        // voters answer 404 and a policy would show it rows it has no claim to.
        if (null !== $organizationId && null === $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId())) {
            $organizationId = null;
        }

        if (!$this->entityManager->isOpen()) {
            return;
        }

        // An already-active transaction belongs to an outer unit of work (a test
        // harness, a wrapping decorator). The setting still applies to it, but
        // resolving it would commit or discard writes this listener never saw —
        // the same rule TransactionBoundary follows.
        $this->ownsTransaction = !$this->entityManager->getConnection()->isTransactionActive();
        if ($this->ownsTransaction) {
            $this->entityManager->beginTransaction();
        }
        $this->tenants->activatePrincipal($principal->getId());
        if (null !== $organizationId) {
            $this->tenants->activate($organizationId);
        }
    }

    public function onException(ExceptionEvent $event): void
    {
        $this->rollBack();
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$this->ownsTransaction) {
            return;
        }
        $this->ownsTransaction = false;

        if (!$this->entityManager->getConnection()->isTransactionActive()) {
            return;
        }

        try {
            // No flush: the controllers commit their own unit of work. A leftover
            // managed entity is discarded here exactly as it was before this
            // listener existed.
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->rollBack();
            throw $exception;
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->rollBack();
    }

    /**
     * Ends the transaction without committing. Safe to call repeatedly and after a
     * failed commit: a closed entity manager has no transaction left to resolve.
     */
    private function rollBack(): void
    {
        if (!$this->ownsTransaction) {
            return;
        }
        $this->ownsTransaction = false;
        if (!$this->entityManager->isOpen()) {
            return;
        }
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }
    }
}
