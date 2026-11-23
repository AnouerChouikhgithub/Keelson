<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Sends what a request queued, once that request's response exists.
 *
 * `kernel.terminate` runs after the response is built (and, under the production
 * runtime, after it is sent), so neither a slow SMTP server nor a transport
 * failure can change its status, headers or body. A failure is logged and the
 * rest of the queue still goes out: one bad recipient must not cost the others
 * their message. Nothing about the failure is written to the response, because
 * the endpoint has already answered "we sent it if that address exists".
 *
 * The log line carries the exception and no address or token.
 */
final class DeferredMailListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly DeferredMailer $deferred,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        foreach ($this->deferred->flush() as $email) {
            try {
                $this->mailer->send($email);
            } catch (\Throwable $exception) {
                $this->logger->error('A deferred email could not be sent', ['exception' => $exception]);
            }
        }
    }
}
