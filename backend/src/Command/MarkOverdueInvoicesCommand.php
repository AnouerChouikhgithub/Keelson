<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\InvoiceOverdueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:invoices:mark-overdue',
    description: 'Record sent invoices past their due date as overdue, with an audit event per transition.',
)]
final class MarkOverdueInvoicesCommand extends Command
{
    public function __construct(private readonly InvoiceOverdueService $overdue)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $transitioned = $this->overdue->markOverdue();
        $io->success(sprintf('Transitioned %d invoice(s) to overdue.', $transitioned));

        return Command::SUCCESS;
    }
}
