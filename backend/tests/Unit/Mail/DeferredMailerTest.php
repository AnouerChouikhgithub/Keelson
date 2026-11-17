<?php

declare(strict_types=1);

namespace App\Tests\Unit\Mail;

use App\Mail\DeferredMailer;
use App\Mail\DeferredMailListener;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * The queue itself, and what the terminate listener does with it.
 *
 * The listener is where a transport failure has to stop: it must be logged, must
 * not stop the messages behind it, and must not be replayed by a later flush.
 */
final class DeferredMailerTest extends TestCase
{
    public function testSendQueuesTheMessageAndFlushEmptiesTheQueue(): void
    {
        $deferred = new DeferredMailer();
        $first = self::message('First');
        $second = self::message('Second');

        $deferred->send($first);
        $deferred->send($second);

        self::assertSame([$first, $second], $deferred->flush());
        self::assertSame([], $deferred->flush(), 'A flush must not hand over what it already sent.');
    }

    public function testTheListenerLogsAFailureAndDeliversTheRest(): void
    {
        $deferred = new DeferredMailer();
        $deferred->send(self::message('Broken'));
        $deferred->send(self::message('Delivered'));

        $mailer = new class implements MailerInterface {
            /** @var list<string> */
            public array $sent = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $subject = $message instanceof Email ? (string) $message->getSubject() : '';
                if ('Broken' === $subject) {
                    throw new TransportException('the transport is down');
                }
                $this->sent[] = $subject;
            }
        };
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = $level.': '.$message;
            }
        };

        $listener = new DeferredMailListener($deferred, $mailer, $logger);
        $listener->onTerminate(new TerminateEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create('/api/auth/forgot-password', 'POST'),
            new Response(),
        ));

        self::assertSame(['Delivered'], $mailer->sent, 'One failure must not cost the rest of the queue its message.');
        self::assertSame(['error: A deferred email could not be sent'], $logger->records);
        self::assertSame([], $deferred->flush(), 'A failed flush must not replay the queue.');
    }

    private static function message(string $subject): Email
    {
        return (new Email())
            ->from('no-reply@keelson.test')
            ->to('owner@example.test')
            ->subject($subject)
            ->text('Body');
    }
}
