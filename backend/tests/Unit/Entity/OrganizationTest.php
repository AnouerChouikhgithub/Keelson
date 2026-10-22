<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

final class OrganizationTest extends TestCase
{
    public function testNormalizesSubdomainAndName(): void
    {
        $organization = new Organization(' Example Org ', ' ACME-1 ');
        $reflection = new \ReflectionClass($organization);
        $subdomain = $reflection->getProperty('subdomain');
        $name = $reflection->getProperty('name');

        self::assertSame('acme-1', $subdomain->getValue($organization));
        self::assertSame('Example Org', $name->getValue($organization));
    }

    public function testRejectsInvalidSubdomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Organization('Example', '-invalid');
    }

    public function testRejectsBlankName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Organization('  ', 'example');
    }
}
