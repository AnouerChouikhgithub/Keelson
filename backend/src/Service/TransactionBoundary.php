<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Runs a unit of work in a transaction this layer owns.
 *
 * An already-active transaction belongs to an outer unit of work (a test, a
 * console command, a wrapping decorator). In that case the work joins it and
 * neither commits nor rolls it back: resolving a caller's transaction would
 * silently commit or discard writes this handler never saw.
 */
final class TransactionBoundary
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function run(callable $work): mixed
    {
        $connection = $this->entityManager->getConnection();
        if ($connection->isTransactionActive()) {
            return $work();
        }

        $this->entityManager->beginTransaction();
        try {
            $result = $work();
            $this->entityManager->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            throw $exception;
        }
    }
}
