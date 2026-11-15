<?php

declare(strict_types=1);

namespace App\Command;

use App\Config\EnvironmentValidator;
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

        return Command::SUCCESS;
    }
}
