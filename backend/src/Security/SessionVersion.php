<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Principal;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The counter that ties a session to the password it was opened with.
 *
 * A session's token outlives a password change on its own. Symfony's
 * `ContextListener` compares the password hash it finds in the session with the
 * one it just loaded from the database — which signs the session out only when
 * those are different objects. The request that changes the password has already
 * refreshed the token, so the session it writes carries the *new* hash, and the
 * other sessions of that account keep working. A password reset that does not end
 * the session it was meant to end is the hole this closes.
 *
 * So the version lives outside the token: an integer on `principals`, an integer
 * in the session. Login records it, anything that rewrites the password
 * increments it, and {@see \App\EventListener\SessionVersionListener} compares the
 * two on every request and ends the session when they disagree.
 *
 * A session without a recorded version adopts the current one on its first
 * request. That covers sessions opened before this marker existed and sessions a
 * test client plants itself; nothing is revoked retroactively, and a reset that
 * happens afterwards is honoured normally.
 */
final class SessionVersion
{
    public const SESSION_KEY = '_keelson_session_version';

    /**
     * Records the version this session was opened with.
     *
     * Writes only on a change, so an authenticated request does not rewrite its
     * session row just to say the same thing again.
     */
    public static function stamp(SessionInterface $session, Principal $principal): void
    {
        $version = $principal->getSessionVersion();
        if ($version === $session->get(self::SESSION_KEY)) {
            return;
        }

        $session->set(self::SESSION_KEY, $version);
    }

    /**
     * True when the session was opened with a password that has since changed.
     *
     * An unstamped session is never stale: it is adopted by {@see stamp()} on this
     * same request, before anything can be decided from a version it never wrote.
     */
    public static function isStale(SessionInterface $session, Principal $principal): bool
    {
        $stamped = $session->get(self::SESSION_KEY);
        if (!\is_int($stamped)) {
            return false;
        }

        return $stamped !== $principal->getSessionVersion();
    }
}
