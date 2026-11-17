<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Mime\Email;

/**
 * Queues transactional mail for the end of the request.
 *
 * Sending inside the handler makes the response time depend on the mail
 * transport. It also makes the two enumeration-safe paths measurable apart: a
 * known address builds and sends a message, an unknown one does nothing, and the
 * difference is visible on the wire. Queueing costs the same for both, and
 * {@see DeferredMailListener} flushes the queue on `kernel.terminate`, after the
 * response has been produced.
 *
 * This class only holds messages; it has no transport, so it cannot fail. The
 * send, and the logging of a failed send, belong to the listener.
 */
final class DeferredMailer
{
    /** @var list<Email> */
    private array $queued = [];

    public function send(Email $email): void
    {
        $this->queued[] = $email;
    }

    /**
     * Hands the queue to the caller and empties it first, so a failure part-way
     * through a flush cannot send the same message twice on the next terminate.
     *
     * @return list<Email>
     */
    public function flush(): array
    {
        $queued = $this->queued;
        $this->queued = [];

        return $queued;
    }
}
