<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    /**
     * One page of the caller's own notifications, newest first.
     *
     * Scoped by `principal_id` in SQL, not after the fact, so another person's
     * rows are never loaded — let alone returned.
     *
     * @param array{id: string, createdAt: \DateTimeImmutable}|null $cursor
     *
     * @return list<Notification>
     */
    public function findPageForPrincipal(string $principalId, ?array $cursor, int $limit, bool $unreadOnly): array
    {
        $builder = $this->createQueryBuilder('notification')
            ->innerJoin('notification.principal', 'principal')
            ->addSelect('principal')
            ->leftJoin('notification.organization', 'organization')
            ->addSelect('organization')
            ->andWhere('IDENTITY(notification.principal) = :principalId')
            ->setParameter('principalId', $principalId)
            ->orderBy('notification.createdAt', 'DESC')
            ->addOrderBy('notification.id', 'DESC')
            ->setMaxResults(max(1, min($limit, 100)) + 1);
        if ($unreadOnly) {
            $builder->andWhere('notification.readAt IS NULL');
        }
        if (null !== $cursor) {
            $builder->andWhere('(notification.createdAt < :cursorCreatedAt OR (notification.createdAt = :cursorCreatedAt AND notification.id < :cursorId))')
                ->setParameter('cursorCreatedAt', $cursor['createdAt'])
                ->setParameter('cursorId', $cursor['id']);
        }

        return $builder->getQuery()->getResult();
    }

    public function findOneForPrincipal(string $notificationId, string $principalId): ?Notification
    {
        return $this->createQueryBuilder('notification')
            ->innerJoin('notification.principal', 'principal')
            ->addSelect('principal')
            ->leftJoin('notification.organization', 'organization')
            ->addSelect('organization')
            ->andWhere('notification.id = :notificationId')
            ->andWhere('IDENTITY(notification.principal) = :principalId')
            ->setParameter('notificationId', $notificationId)
            ->setParameter('principalId', $principalId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return int rows changed */
    public function markAllRead(string $principalId, \DateTimeImmutable $now): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE notifications SET read_at = :now WHERE principal_id = :principalId AND read_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:sP'), 'principalId' => $principalId],
        );
    }

    /**
     * Duplicate guard for automatic notifications about one invoice.
     *
     * The command and the paid transition can both run repeatedly for the same
     * invoice; "one overdue notice per person per invoice" is what stops a
     * nightly cron from growing the table forever.
     */
    public function hasInvoiceNotification(string $principalId, string $type, string $invoiceId): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT 1 FROM notifications WHERE principal_id = ? AND type = ? AND data->>'invoiceId' = ? LIMIT 1",
            [$principalId, $type, $invoiceId],
        );
    }
}
