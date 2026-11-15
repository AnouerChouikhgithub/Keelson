<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Safety gate for synthetic fixture seeding.
 *
 * The loader must judge the database it will actually write to, not the raw
 * `DATABASE_URL`. Doctrine rewrites the URL into connection parameters and, in
 * the test environment, appends `dbname_suffix` (`_test`). Reading the raw URL
 * made a correctly isolated CI database (`keelson` + `_test`) look like the
 * production name `keelson`, so the loader refused its own test target. The
 * effective parameters are what PostgreSQL will receive, so they are what gets
 * validated here.
 *
 * Every restriction is kept: production, non-PostgreSQL drivers, non-loopback
 * hosts and ambiguous database names are refused. Messages never include the
 * user, password or port.
 */
final class FixtureTargetGuard
{
    private const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];
    private const SAFE_NAME_PATTERN = '/(?:^|_)(test|dev|fixture)(?:_|$)/';
    private const POSTGRES_DRIVERS = ['pdo_pgsql', 'pgsql', 'postgres', 'postgresql'];

    /**
     * @param array<string, mixed> $connectionParams effective DBAL parameters (Connection::getParams())
     *
     * @return string a credential-free description of the target, e.g. "keelson_test_test@127.0.0.1"
     */
    public function describe(array $connectionParams, string $environment): string
    {
        if (str_starts_with($environment, 'prod')) {
            throw new \RuntimeException(sprintf('Refusing to load synthetic fixtures in the "%s" environment.', $environment));
        }

        $driver = strtolower((string) ($connectionParams['driver'] ?? ''));
        if (!in_array($driver, self::POSTGRES_DRIVERS, true)) {
            throw new \RuntimeException(sprintf('Refusing to load synthetic fixtures: the configured driver "%s" is not PostgreSQL.', $driver));
        }

        $host = $this->host($connectionParams['host'] ?? null);
        if (!in_array($host, self::LOOPBACK_HOSTS, true)) {
            throw new \RuntimeException(sprintf('Refusing to load synthetic fixtures: the database host "%s" is not loopback.', $host));
        }

        $database = (string) ($connectionParams['dbname'] ?? '');
        if (1 !== preg_match(self::SAFE_NAME_PATTERN, $database)) {
            throw new \RuntimeException(sprintf('Refusing to load synthetic fixtures into "%s": the effective database name must look local (contains test/dev/fixture).', $database));
        }

        return sprintf('%s@%s', $database, $host);
    }

    private function host(mixed $value): string
    {
        $host = strtolower(trim((string) $value));

        // DBAL keeps IPv6 literals unbracketed; accept either spelling.
        return trim($host, '[]');
    }
}
