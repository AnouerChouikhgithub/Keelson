<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Principal;
use Symfony\Component\Mime\Email;

/**
 * Builds the two transactional emails as plain text.
 *
 * No templating engine is installed, and these messages have a single variable
 * each, so they are composed here. Tokens are interpolated into the link and
 * nowhere else: no log line, exception message or response body ever receives
 * one.
 */
final class MailFactory
{
    public function __construct(
        private readonly string $frontendUrl,
        private readonly string $fromAddress = 'no-reply@keelson.local',
    ) {
    }

    public function verifyEmail(Principal $principal, string $token): Email
    {
        $url = $this->link('/verify-email', $token);

        return $this->base($principal)
            ->subject('Confirm your Keelson email address')
            ->text(<<<TEXT
                Welcome to Keelson.

                Confirm your email address to finish setting up your account:

                {$url}

                The link is valid for 24 hours. If you did not create an account, ignore this message.
                TEXT);
    }

    public function resetPassword(Principal $principal, string $token): Email
    {
        $url = $this->link('/reset-password', $token);

        return $this->base($principal)
            ->subject('Reset your Keelson password')
            ->text(<<<TEXT
                Someone asked to reset the password for this Keelson account.

                Choose a new password here:

                {$url}

                The link is valid for 1 hour and can only be used once. If this was not you, ignore this message.
                TEXT);
    }

    public function invitation(string $organizationName, Principal $inviter, string $email, string $role, string $token): Email
    {
        $url = $this->link('/accept-invitation', $token);
        $inviterName = $inviter->getDisplayName() ?? $inviter->getEmail();

        return (new Email())
            ->from($this->fromAddress)
            ->to($email)
            ->subject(sprintf('%s invited you to %s on Keelson', $inviterName, $organizationName))
            ->text(<<<TEXT
                {$inviterName} invited you to join {$organizationName} on Keelson as {$role}.

                Accept the invitation here:

                {$url}

                The link is valid for 7 days. If you were not expecting it, ignore this message.
                TEXT);
    }

    private function base(Principal $principal): Email
    {
        return (new Email())
            ->from($this->fromAddress)
            ->to($principal->getEmail());
    }

    private function link(string $path, string $token): string
    {
        return rtrim($this->frontendUrl, '/').$path.'?token='.rawurlencode($token);
    }
}
