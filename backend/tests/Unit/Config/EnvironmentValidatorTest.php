<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use App\Config\EnvironmentValidator;
use PHPUnit\Framework\TestCase;

final class EnvironmentValidatorTest extends TestCase
{
    private EnvironmentValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new EnvironmentValidator();
    }

    public function testACompleteProductionConfigurationHasNoFindings(): void
    {
        $result = $this->validator->validate($this->productionConfiguration(), 'prod');

        self::assertSame([], $result['errors']);
        self::assertSame([], $result['warnings']);
    }

    public function testProductionReportsEveryMissingVariableByName(): void
    {
        $result = $this->validator->validate([], 'prod');
        $report = implode("\n", $result['errors']);

        foreach (['APP_SECRET', 'DATABASE_URL', 'DEFAULT_URI', 'FRONTEND_URL', 'MAILER_DSN', 'TRUSTED_HOSTS'] as $name) {
            self::assertStringContainsString($name, $report);
        }
    }

    public function testFindingsNeverContainTheConfiguredValues(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['APP_SECRET'] = str_repeat('s', 31);
        $configuration['MAILER_DSN'] = 'smtp://a-real-looking-password@smtp.example.test:25';

        $result = $this->validator->validate($configuration, 'prod');
        $report = implode("\n", array_merge($result['errors'], $result['warnings']));

        self::assertStringNotContainsString('a-real-looking-password', $report);
        self::assertStringNotContainsString(str_repeat('s', 31), $report);
    }

    public function testDevelopmentCredentialsAndHttpAreRejectedInProduction(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['DATABASE_URL'] = 'postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16';
        $configuration['DEFAULT_URI'] = 'http://127.0.0.1:8000';
        $configuration['FRONTEND_URL'] = 'http://127.0.0.1:5173';

        $report = implode("\n", $this->validator->validate($configuration, 'prod')['errors']);

        self::assertStringContainsString('app:app', $report);
        self::assertStringContainsString('https', $report);
    }

    public function testDebugMustBeDisabledInProduction(): void
    {
        $result = $this->validator->validate($this->productionConfiguration(), 'prod', true);

        self::assertStringContainsString('APP_DEBUG', implode("\n", $result['errors']));
    }

    public function testTheNullMailTransportCannotDeliverLinksInProduction(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['MAILER_DSN'] = 'null://null';

        $report = implode("\n", $this->validator->validate($configuration, 'prod')['errors']);

        self::assertStringContainsString('MAILER_DSN', $report);
    }

    public function testAMalformedFrontendUrlIsAlwaysAnError(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['FRONTEND_URL'] = 'localhost:5173';

        $report = implode("\n", $this->validator->validate($configuration, 'prod')['errors']);

        self::assertStringContainsString('FRONTEND_URL', $report);
    }

    public function testMissingMailAndFrontendConfigurationIsOnlyAWarningOutsideProduction(): void
    {
        $result = $this->validator->validate([
            'APP_SECRET' => 'dev-secret',
            'DATABASE_URL' => 'postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16',
            'DEFAULT_URI' => 'http://127.0.0.1:8000',
        ], 'dev');

        self::assertSame([], $result['errors']);
        $warnings = implode("\n", $result['warnings']);
        self::assertStringContainsString('FRONTEND_URL', $warnings);
        self::assertStringContainsString('MAILER_DSN', $warnings);
    }

    public function testTrustedHeadersAreRequiredWithTrustedProxies(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['TRUSTED_PROXIES'] = '10.0.0.0/8';
        $configuration['TRUSTED_HEADERS'] = '';

        $result = $this->validator->validate($configuration, 'prod');

        self::assertStringContainsString('TRUSTED_HEADERS', implode("\n", $result['errors']));
    }

    /** @return array<string, string> */
    private function productionConfiguration(): array
    {
        return [
            'APP_SECRET' => str_repeat('a', 32),
            'DATABASE_URL' => 'postgresql://keelson:password@db.internal:5432/keelson?serverVersion=16',
            'DEFAULT_URI' => 'https://keelson.example.com',
            'FRONTEND_URL' => 'https://app.keelson.example.com',
            'MAILER_DSN' => 'smtp://mail.internal:587',
            'TRUSTED_HOSTS' => 'keelson.example.com',
        ];
    }
}
