<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\EnvironmentValidator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints which required variables are present without printing their values.
 *
 * Safe to run in a deployment pipeline log: the output names variables and
 * problems only.
 */
#[AsCommand(name: 'app:config:check', description: 'Checks environment configuration without printing secret values.')]
final class CheckConfigurationCommand extends Command
{
    /** @param array<string, mixed> $configuration */
    public function __construct(
        private readonly EnvironmentValidator $validator,
        private readonly Connection $connection,
        private readonly string $environment,
        private readonly bool $debug,
        private readonly array $configuration,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Configuration check (%s)', $this->environment));

        $result = $this->validator->validate($this->configuration, $this->environment, $this->debug);
        foreach ($result['errors'] as $error) {
            $io->error($error);
        }
        foreach ($result['warnings'] as $warning) {
            $io->warning($warning);
        }

        if ([] !== $result['errors']) {
            $io->error('Required configuration is incomplete. No values were printed.');

            return Command::FAILURE;
        }

        $io->success('Required configuration for this environment is present.');

        // The effective target, not the one in the file the reader has in mind. Symfony
        // loads `.env`, then `.env.local`, then `.env.<APP_ENV>.local`; the last file wins,
        // which is how a leftover `.env.dev.local` silently redirects a command.
        $params = $this->connection->getParams();
        $io->writeln(sprintf(
            'Database: <info>%s:%s/%s</info>',
            \is_string($params['host'] ?? null) ? $params['host'] : 'unknown',
            \is_int($params['port'] ?? null) ? $params['port'] : '5432',
            \is_string($params['dbname'] ?? null) ? $params['dbname'] : 'unknown',
        ));
        $io->writeln(sprintf(
            'Environment files: .env, then .env.local, then .env.%s.local (last one wins).',
            $this->environment,
        ));

        return Command::SUCCESS;
    }
}
