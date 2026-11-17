<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Notification;
use App\Exception\ApiException;
use App\Repository\NotificationRepository;
use App\Util\Cursor;
use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The caller's own notifications.
 *
 * Every query is scoped by the authenticated principal; a notification id that
 * belongs to someone else is a 404, exactly like a row that does not exist, so
 * ids cannot be used to probe other people's activity.
 */
final class NotificationController extends AbstractController
{
    use CurrentPrincipalTrait;

    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/notifications', name: 'app_api_notifications_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $principal = $this->currentPrincipal();
        $limit = $this->limit($request);
        $cursor = Cursor::decode($request->query->get('cursor'));
        $unreadOnly = '1' === (string) $request->query->get('unread', '');

        $rows = $this->notifications->findPageForPrincipal($principal->getId(), $cursor, $limit, $unreadOnly);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];

        return $this->json([
            'items' => array_map(fn (Notification $notification): array => $this->serialize($notification), $rows),
            'nextCursor' => $hasMore && null !== $last ? Cursor::encode($last->getCreatedAt(), $last->getId()) : null,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    #[Route('/api/notifications/{notificationId}/read', name: 'app_api_notifications_read', methods: ['POST'])]
    public function markRead(string $notificationId): JsonResponse
    {
        if (!Uuid::isValid($notificationId)) {
            throw ApiException::invalidId();
        }
        $principal = $this->currentPrincipal();
        $notification = $this->notifications->findOneForPrincipal($notificationId, $principal->getId());
        if (null === $notification) {
            throw ApiException::notFound();
        }
        if ($notification->markRead()) {
            $this->entityManager->flush();
        }

        return $this->json(['notification' => $this->serialize($notification)]);
    }

    #[Route('/api/notifications/read-all', name: 'app_api_notifications_read_all', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $principal = $this->currentPrincipal();

        return $this->json(['updated' => $this->notifications->markAllRead($principal->getId(), new \DateTimeImmutable())]);
    }

    /** @return array<string, mixed> */
    private function serialize(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'data' => $notification->getData(),
            'organizationId' => $notification->getOrganization()?->getId(),
            'read' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format(DATE_ATOM),
            'readAt' => $notification->getReadAt()?->format(DATE_ATOM),
        ];
    }

    private function limit(Request $request): int
    {
        $raw = $request->query->get('limit');
        if (null === $raw || '' === $raw) {
            return self::DEFAULT_LIMIT;
        }
        if (1 !== preg_match('/^[0-9]{1,3}$/', (string) $raw) || (int) $raw < 1) {
            throw ApiException::badRequest('limit must be a positive integer.');
        }

        return min((int) $raw, self::MAX_LIMIT);
    }
}
