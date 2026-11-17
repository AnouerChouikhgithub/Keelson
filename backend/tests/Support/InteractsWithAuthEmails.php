<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Mime\Email;

/**
 * Reads the link a verification or reset email carries.
 *
 * The database only stores a hash, so parsing the delivered message is the only
 * way to complete the flow a user completes. It also keeps the tests honest: if
 * the email stops containing a usable link, they fail.
 */
trait InteractsWithAuthEmails
{
    protected function lastEmailToken(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message, 'No email was sent.');

        $body = (string) $message->getTextBody();
        if (1 !== preg_match('/[?&]token=([A-Za-z0-9_-]+)/', $body, $matches)) {
            self::fail('The email does not contain a token link: '.$body);
        }

        return $matches[1];
    }

    protected function lastEmailRecipient(): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message, 'No email was sent.');

        return (string) $message->getTo()[0]->getAddress();
    }
}
