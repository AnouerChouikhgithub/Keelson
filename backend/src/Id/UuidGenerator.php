<?php

declare(strict_types=1);

namespace App\Id;

use App\Util\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Id\AbstractIdGenerator;

final class UuidGenerator extends AbstractIdGenerator
{
    public function generateId(EntityManagerInterface $em, ?object $entity): string
    {
        return Uuid::v4();
    }
}
