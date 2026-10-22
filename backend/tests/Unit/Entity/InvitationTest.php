<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Invitation;
use App\Entity\Organization;
use App\Entity\Principal;
use PHPUnit\Framework\TestCase;

final class InvitationTest extends TestCase
{
    public function testRejectsOwnerInvitation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInvitation(role: 'Owner');
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInvitation(email: 'not-an-email');
    }

    public function testRequiresFutureExpiry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInvitation(expiresAt: new \DateTimeImmutable('-1 minute'));
    }

    public function testRejectsShortTokenVerifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInvitation(tokenHash: 'short');
    }

    private function createInvitation(
        string $role = 'Member',
        string $email = 'member@example.test',
        string $tokenHash = 'abbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ?\DateTimeImmutable $expiresAt = null,
    ): Invitation {
        return new Invitation(
            new Organization('Example', 'example'),
            $email,
            $role,
            $tokenHash,
            new Principal('https://issuer.example', 'subject-1'),
            $expiresAt ?? new \DateTimeImmutable('+72 hours'),
        );
    }
}
