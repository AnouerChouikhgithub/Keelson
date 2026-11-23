<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Principal;
use App\Tests\Support\InteractsWithAuthEmails;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Transactional mail leaves after the response, not while building it.
 *
 * Both halves matter for enumeration safety: the answer must be identical for a
 * known and an unknown address, and a mail transport that is slow or down must
 * not turn one of them into a different status, a different body or a different
 * amount of time before the response.
 */
final class DeferredMailTest extends WebTestCase
{
    use InteractsWithAuthEmails;

    private const ORIGIN = ['HTTP_ORIGIN' => 'http://localhost'];
    private const PASSWORD = 'Correct-Horse-9';

    private EntityManagerInterface $entityManager;
    private KernelBrowser $client;
    private string $suffix;
    private string $email;

    protected function setUp(): void
    {
        self::getClient(null);
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get('doctrine')->getManager();
        $this->suffix = bin2hex(random_bytes(6));
        $this->email = 'deferred-'.$this->suffix.'@example.test';

        $principal = new Principal($this->email, 'Deferred Mail');
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $principal->setPasswordHash($hasher->hashPassword($principal, self::PASSWORD));
        $principal->markEmailVerified();
        $this->entityManager->persist($principal);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->entityManager) && $this->entityManager->isOpen()) {
                $this->entityManager->getConnection()->executeStatement(
                    'DELETE FROM principals WHERE email LIKE ?',
                    ['deferred-'.$this->suffix.'%'],
                );
                $this->entityManager->close();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * The mail still goes out — the client runs `kernel.terminate`, so a message
     * recorded here was sent after the response was produced — and the unknown
     * address answers exactly the same thing while sending nothing.
     */
    public function testKnownAndUnknownAddressesAnswerIdenticallyAndMailStillLeaves(): void
    {
        $this->forgot($this->email);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(1);
        self::assertSame($this->email, $this->lastEmailRecipient());

        $this->forgot('deferred-unknown-'.$this->suffix.'@example.test');

        self::assertResponseStatusCodeSame(202);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertEmailCount(0);
    }

    /**
     * A transport that throws is logged at termination and cannot reach the
     * response, so a mail outage does not announce which addresses exist.
     */
    public function testAMailTransportFailureLeavesTheResponseAlone(): void
    {
        $mailer = new class implements MailerInterface {
            public int $attempts = 0;

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                ++$this->attempts;

                throw new TransportException('the transport is down');
            }
        };
        self::getContainer()->set('mailer', $mailer);

        // The known address queues one message; the failure surfaces only in the
        // deferred send, after the response.
        $this->forgot($this->email);
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $mailer->attempts);

        // An unknown address queues nothing, and answers the same.
        $this->forgot('deferred-unknown-'.$this->suffix.'@example.test');
        self::assertResponseStatusCodeSame(202);
        self::assertSame(1, $mailer->attempts);
    }

    private function forgot(string $email): void
    {
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            server: ['CONTENT_TYPE' => 'application/json'] + self::ORIGIN,
            content: json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );
    }
}
