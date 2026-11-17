# ADR 0002 — First-party email and password, no external identity provider

- **Status:** accepted
- **Date:** 2026-11-16

## Context

The starter has to work with nothing but its own database: no tenant of this
template should need to create an OAuth application before they can sign in. The
authentication requirements are ordinary — register, verify an address, sign in,
reset a password, accept an invitation — and every one of them is a Symfony
feature.

An external provider (Auth0, Cognito, Keycloak, WorkOS…) would remove password
storage but add a vendor, a redirect flow, a webhook surface and a second source
of truth for identities.

## Decision

Implement authentication in the application:

- Passwords are hashed with Symfony's configured password hasher; plaintext is
  never stored or logged.
- Email verification and password reset are single-use, hashed, time-limited
  tokens (`AuthToken`), mailed through `symfony/mailer`.
- Sign-in is JSON (`json_login`), rate limited per address and per client
  address, and answers `403 email_not_verified` only after the password has been
  accepted, so it cannot be used to enumerate accounts.
- Invitations create a membership; accepting with an unknown address creates the
  principal verified, because receiving the mail proves control of the mailbox.

## Consequences

- No vendor account, no client secret, no redirect URI to configure: a new
  deployment only needs a database and an SMTP endpoint.
- Password storage, reset-token hygiene and account enumeration are now our
  responsibility, and are covered by tests (`LoginThrottlingTest`,
  `PasswordResetTest`, `RegistrationTest`, `CsrfProtectionTest`).
- There is no MFA, no social login and no enterprise SSO. Adding one later means
  adding an identity provider *next to* the local accounts, with an account
  linking decision that this ADR does not make.
- Changing the password signs other sessions out because Symfony compares the
  stored hash on the next request; that is a consequence of owning the flow, not
  a feature we had to build.
