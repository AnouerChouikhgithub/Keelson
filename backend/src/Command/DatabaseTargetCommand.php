<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the database this environment will actually connect to.
 *
 * `bin/dev-reset.sh` and `bin/dev-reset.ps1` must refuse before they drop
 * anything, and a raw `DATABASE_URL` is the wrong thing to judge: Doctrine
 * rewrites it into connection parameters and the test environment appends
 * `dbname_suffix`. This reads the effective parameters, prints only
 * `database@host` — never a user, password or port — and does not connect, so it
 * also works when the database does not exist yet.
 */
#[AsCommand(
    name: 'app:db:target',
    description: 'Print the effective database name and host of this environment as database@host (no credentials).',
)]
final class DatabaseTargetCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->entityManager->getConnection()->getParams();
        $database = (string) ($params['dbname'] ?? '');
        $host = trim((string) ($params['host'] ?? ''), '[]');
        if ('' === $database || '' === $host) {
            $output->writeln('<error>The configured connection does not declare a database name and host.</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf('%s@%s', $database, strtolower($host)));

        return Command::SUCCESS;
    }
}
