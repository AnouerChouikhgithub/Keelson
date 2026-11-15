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
        foreach (['APP_SECRET', 'DATABASE_URL', 'DEFAULT_URI', 'OIDC_ISSUER', 'OIDC_WELL_KNOWN_URL', 'OIDC_CLIENT_ID', 'OIDC_CLIENT_SECRET', 'TRUSTED_HOSTS'] as $name) {
            self::assertStringContainsString($name, $report);
        }
    }

    public function testFindingsNeverContainTheConfiguredValues(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['APP_SECRET'] = str_repeat('s', 31);
        $configuration['OIDC_CLIENT_SECRET'] = 'a-real-looking-secret-value';

        $result = $this->validator->validate($configuration, 'prod');
        $report = implode("\n", array_merge($result['errors'], $result['warnings']));

        self::assertStringNotContainsString('a-real-looking-secret-value', $report);
        self::assertStringNotContainsString(str_repeat('s', 31), $report);
    }

    public function testDevelopmentCredentialsAndHttpAreRejectedInProduction(): void
    {
        $configuration = $this->productionConfiguration();
        $configuration['DATABASE_URL'] = 'postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16';
        $configuration['DEFAULT_URI'] = 'http://127.0.0.1:8000';

        $report = implode("\n", $this->validator->validate($configuration, 'prod')['errors']);

        self::assertStringContainsString('app:app', $report);
        self::assertStringContainsString('https', $report);
    }

    public function testDebugMustBeDisabledInProduction(): void
    {
        $result = $this->validator->validate($this->productionConfiguration(), 'prod', true);

        self::assertStringContainsString('APP_DEBUG', implode("\n", $result['errors']));
    }

    public function testMissingOidcIsOnlyAWarningOutsideProduction(): void
    {
        $result = $this->validator->validate([
            'APP_SECRET' => 'dev-secret',
            'DATABASE_URL' => 'postgresql://app:app@127.0.0.1:5433/keelson?serverVersion=16',
            'DEFAULT_URI' => 'http://127.0.0.1:8000',
        ], 'dev');

        self::assertSame([], $result['errors']);
        self::assertStringContainsString('OIDC_CLIENT_ID', implode("\n", $result['warnings']));
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
            'OIDC_ISSUER' => 'https://tenant.logto.app/oidc',
            'OIDC_WELL_KNOWN_URL' => 'https://tenant.logto.app/oidc/.well-known/openid-configuration',
            'OIDC_CLIENT_ID' => 'client-id',
            'OIDC_CLIENT_SECRET' => 'client-secret',
            'TRUSTED_HOSTS' => 'keelson.example.com',
        ];
    }
}
