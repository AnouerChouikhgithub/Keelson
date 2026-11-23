<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Principal;
use App\Security\SessionVersion;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The marker a password change moves, in isolation from the HTTP layer.
 *
 * The end-to-end behaviour (a reset signs the session out) is asserted in
 * tests/Integration/PasswordResetTest; this pins the rule the listener applies:
 * a stamped session is stale exactly when the account's version has moved.
 */
final class SessionVersionTest extends TestCase
{
    public function testAStampedSessionIsCurrentUntilThePasswordChanges(): void
    {
        $principal = new Principal('session-version@example.test');
        $session = new Session(new MockArraySessionStorage());

        SessionVersion::stamp($session, $principal);

        self::assertFalse(SessionVersion::isStale($session, $principal));

        $principal->invalidateSessions();

        self::assertTrue(SessionVersion::isStale($session, $principal));
    }

    public function testAnUnstampedSessionIsAdoptedRatherThanEnded(): void
    {
        $principal = new Principal('legacy-session@example.test');
        $session = new Session(new MockArraySessionStorage());

        // A session opened before the marker existed has no version to compare,
        // so it is not stale; it is adopted by the listener on its first request.
        self::assertFalse(SessionVersion::isStale($session, $principal));

        SessionVersion::stamp($session, $principal);
        $principal->invalidateSessions();

        // Adoption is a one-time grace, not an exemption from later resets.
        self::assertTrue(SessionVersion::isStale($session, $principal));
    }
}
