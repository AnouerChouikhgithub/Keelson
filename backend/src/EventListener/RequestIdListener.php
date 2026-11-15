<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Assigns a correlation id to every main request and echoes it back.
 *
 * A client-supplied id is honoured only when it matches a conservative
 * character set: the header is otherwise attacker-controlled text that would
 * end up in every log line. The id is echoed on the response (including error
 * responses) so a user can quote it when reporting an incident.
 */
final class RequestIdListener implements EventSubscriberInterface
{
    public const HEADER = 'X-Request-Id';
    private const PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/';

    public static function getSubscribedEvents(): array
    {
        return [
            // Before the firewall and router, so authentication failures carry it too.
            KernelEvents::REQUEST => ['onKernelRequest', 512],
            KernelEvents::RESPONSE => ['onKernelResponse', -512],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $incoming = $request->headers->get(self::HEADER);
        $requestId = \is_string($incoming) && 1 === preg_match(self::PATTERN, $incoming)
            ? $incoming
            : bin2hex(random_bytes(8));

        $request->attributes->set('_request_id', $requestId);
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = $event->getRequest()->attributes->get('_request_id');
        if (\is_string($requestId) && '' !== $requestId && !$event->getResponse()->headers->has(self::HEADER)) {
            $event->getResponse()->headers->set(self::HEADER, $requestId);
        }
    }
}
