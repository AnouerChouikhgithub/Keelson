<?php

declare(strict_types=1);

namespace App\Command;

use App\DataFixtures\SyntheticFixtureLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fixtures:load',
    description: 'Load idempotent synthetic demo principals, organizations, memberships, customers and invoices into a local test/dev database.',
)]
final class LoadSyntheticFixturesCommand extends Command
{
    public function __construct(private readonly SyntheticFixtureLoader $loader)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation (still refuses unsafe targets).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $target = $this->loader->assertSafeTarget();
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->note(sprintf('Synthetic fixture target: %s', $target));
        $io->text('This seeds fake example.test data only. It never deletes, truncates or updates existing records, and it is not run automatically.');

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('Refusing to seed without --force in a non-interactive shell.');

                return Command::FAILURE;
            }
            if (!$io->confirm('Seed synthetic fixtures into this database?', false)) {
                $io->warning('Aborted; nothing was written.');

                return Command::SUCCESS;
            }
        }

        $stats = $this->loader->load();
        $rows = [];
        foreach ($stats['created'] as $type => $created) {
            $rows[] = [$type, (string) $created, (string) $stats['skipped'][$type]];
        }
        $io->table(['Record', 'Created', 'Already present'], $rows);
        $io->success(sprintf('Synthetic fixtures loaded into %s.', $stats['target']));

        return Command::SUCCESS;
    }
}
