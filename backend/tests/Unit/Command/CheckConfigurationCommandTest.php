<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CheckConfigurationCommand;
use App\Config\EnvironmentValidator;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The command is the one place a developer checks "which database will this use?",
 * so it prints the effective host and name plus the file order that produced them.
 */
final class CheckConfigurationCommandTest extends TestCase
{
    public function testItPrintsTheEffectiveDatabaseAndTheWinningEnvironmentFile(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => 'db.internal',
            'port' => 6543,
            'dbname' => 'keelson_check_test',
            'user' => 'keelson_app',
            'password' => 'not-printed',
        ]);

        $command = new CheckConfigurationCommand(
            new EnvironmentValidator(),
            $connection,
            'test',
            false,
            [
                'APP_SECRET' => str_repeat('t', 32),
                'DATABASE_URL' => 'postgresql://keelson_app:not-printed@db.internal:6543/keelson_check_test',
                'DEFAULT_URI' => 'http://localhost',
                'MAILER_DSN' => 'null://null',
                'FRONTEND_URL' => 'http://localhost',
            ],
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([]);
        $output = $tester->getDisplay();

        self::assertSame(0, $status);
        self::assertStringContainsString('db.internal:6543/keelson_check_test', $output);
        self::assertStringContainsString('.env.test.local (last one wins)', $output);
        self::assertStringNotContainsString('not-printed', $output, 'Credentials must never be printed.');
    }
}
