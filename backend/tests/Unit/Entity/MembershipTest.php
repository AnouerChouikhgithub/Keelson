<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\Principal;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testRejectsUnknownRole(): void
    {
        $organization = new Organization('Example', 'example');
        $principal = new Principal('https://issuer.example', 'subject-1');

        $this->expectException(\InvalidArgumentException::class);
        new Membership($organization, $principal, 'SuperAdmin');
    }
}
