<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase
{
    public function testGeneratesCanonicalVersionFourUuids(): void
    {
        $uuid = Uuid::v4();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
        );
    }

    public function testGeneratesDistinctIdentifiers(): void
    {
        $uuids = [];
        for ($i = 0; $i < 1000; ++$i) {
            $uuids[] = Uuid::v4();
        }

        self::assertCount(1000, array_unique($uuids));
    }
}
