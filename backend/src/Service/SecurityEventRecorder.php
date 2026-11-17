<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Principal;
use App\Entity\SecurityEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes one security fact per call, with the request context attached.
 *
 * Recording must never break the request it describes: a failure to write the
 * trail is logged by the caller's transaction if it is inside one, but this class
 * does not swallow anything — it is called from handlers that already own a
 * transaction, so the row and the action commit or roll back together.
 *
 * Redaction is structural, not cosmetic: the type is a constant from
 * {@see SecurityEvent}, and only the small `$data` arrays below are accepted, so
 * no code path can pass a password or a token through.
 */
final class SecurityEventRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @param array<string, scalar|null> $data */
    public function record(string $type, ?Principal $principal = null, array $data = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $this->entityManager->persist(new SecurityEvent(
            $type,
            $principal?->getId(),
            $request?->getClientIp(),
            $request?->headers->get('User-Agent'),
            $this->requestId($request),
            $data,
        ));
        $this->entityManager->flush();
    }

    /**
     * A failed sign-in must not become an account-existence oracle: the address is
     * reduced to a truncated SHA-256 so repeated attempts correlate without the
     * table holding the address itself.
     */
    public static function emailFingerprint(string $email): string
    {
        return substr(hash('sha256', strtolower(trim($email))), 0, 16);
    }

    /** Prefers a proxy-supplied id, otherwise the one the kernel generated. */
    private function requestId(?Request $request): ?string
    {
        if (null === $request) {
            return null;
        }

        foreach (['X-Request-Id', 'X-Correlation-Id'] as $header) {
            $value = $request->headers->get($header);
            if (\is_string($value) && '' !== $value) {
                return mb_substr($value, 0, 64);
            }
        }

        return null;
    }
}
