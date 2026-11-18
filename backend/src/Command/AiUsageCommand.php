<?php

declare(strict_types=1);

namespace App\Command;

use App\Ai\AiBudgetPolicy;
use App\Repository\AiRequestRecordRepository;
use App\Repository\OrganizationAiSettingsRepository;
use App\Repository\OrganizationRepository;
use App\Tenancy\TenantContext;
use App\Util\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints what the AI ledger recorded, per organization and per UTC day.
 *
 * This is a read-only operator tool. It shows the same numbers the budget is enforced
 * from (`used_tokens`), plus the day-by-day breakdown the ledger keeps and the monthly
 * counters do not. Nothing here talks to a provider, and nothing here prints prompt
 * text: the ledger does not store any.
 *
 * `ai_requests` and `organization_ai_settings` are tenant tables under row-level
 * security, so every read runs inside `TenantContext::run()` for the organization it
 * belongs to. A console command has no request transaction to inherit, which is exactly
 * the case the tenant helper exists for; without it every table would read as empty.
 */
#[AsCommand(
    name: 'app:ai:usage',
    description: 'Print AI token usage per organization per day, and the monthly budget state.',
)]
final class AiUsageCommand extends Command
{
    private const DEFAULT_DAYS = 30;

    public function __construct(
        private readonly AiRequestRecordRepository $records,
        private readonly OrganizationAiSettingsRepository $settings,
        private readonly OrganizationRepository $organizations,
        private readonly TenantContext $tenants,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', 'o', InputOption::VALUE_REQUIRED, 'Only this organization id.')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'How many days back to report.', (string) self::DEFAULT_DAYS)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the same numbers as JSON instead of tables.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = filter_var($input->getOption('days'), FILTER_VALIDATE_INT);
        if (!is_int($days) || $days < 1 || $days > 366) {
            $io->error('--days must be an integer between 1 and 366.');

            return Command::INVALID;
        }

        $requested = $input->getOption('organization');
        if (null !== $requested && !Uuid::isValid((string) $requested)) {
            $io->error('--organization must be a UUID.');

            return Command::INVALID;
        }

        $organization = null === $requested ? null : $this->organizations->find((string) $requested);
        $organizations = null === $requested
            ? $this->organizations->findBy(['status' => 'active'], ['name' => 'ASC'])
            : (null === $organization ? [] : [$organization]);

        if ([] === $organizations) {
            $io->warning('No matching organization.');

            return Command::SUCCESS;
        }

        $today = new \DateTimeImmutable('today');
        $from = $today->modify(sprintf('-%d days', $days - 1));
        $to = $today->modify('+1 day');
        $period = $today->format('Y-m');

        $report = [];
        foreach ($organizations as $organization) {
            $organizationId = $organization->getId();
            $daily = $this->tenants->run(
                $organizationId,
                fn (): array => $this->records->dailyUsage($organizationId, $from, $to),
            );
            $settings = $this->tenants->run(
                $organizationId,
                fn () => $this->settings->findForOrganization($organizationId),
            );

            $total = 0;
            foreach ($daily as $day) {
                $total += $day['totalTokens'];
            }

            $report[] = [
                'organizationId' => $organizationId,
                'name' => $organization->getName(),
                'enabled' => null !== $settings && $settings->isEnabled(),
                'monthlyTokenBudget' => $settings?->getMonthlyTokenBudget() ?? 0,
                'budgetRemainingTokens' => $settings?->remainingTokens($period) ?? 0,
                'windowTokens' => $total,
                'days' => $daily,
            ];
        }

        if (true === $input->getOption('json')) {
            $output->writeln((string) json_encode($report, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->title(sprintf('AI usage, %s to %s (UTC days)', $from->format('Y-m-d'), $today->format('Y-m-d')));

        foreach ($report as $entry) {
            $io->section(sprintf('%s (%s)', $entry['name'], $entry['organizationId']));
            $io->writeln(sprintf(
                'AI %s; monthly budget %s tokens; %s remaining this month.',
                $entry['enabled'] ? 'enabled' : 'disabled',
                number_format($entry['monthlyTokenBudget']),
                number_format($entry['budgetRemainingTokens']),
            ));

            if ([] === $entry['days']) {
                $io->writeln('No AI request was recorded in this window.');

                continue;
            }

            $rows = [];
            $spikes = [];
            foreach ($entry['days'] as $day) {
                $rows[] = [
                    $day['day'],
                    number_format($day['requests']),
                    number_format($day['failed']),
                    number_format($day['promptTokens']),
                    number_format($day['completionTokens']),
                    number_format($day['totalTokens']),
                ];
                if ($day['totalTokens'] > AiBudgetPolicy::ALERT_DAILY_TOKENS) {
                    $spikes[] = sprintf('%s (%s tokens)', $day['day'], number_format($day['totalTokens']));
                }
            }

            $rows[] = [
                'total',
                number_format(array_sum(array_column($entry['days'], 'requests'))),
                number_format(array_sum(array_column($entry['days'], 'failed'))),
                number_format(array_sum(array_column($entry['days'], 'promptTokens'))),
                number_format(array_sum(array_column($entry['days'], 'completionTokens'))),
                number_format($entry['windowTokens']),
            ];

            $io->table(['UTC day', 'requests', 'failed', 'prompt', 'completion', 'total'], $rows);

            if ([] !== $spikes) {
                $io->warning(sprintf(
                    'Daily usage above the %s-token alert threshold on: %s.',
                    number_format(AiBudgetPolicy::ALERT_DAILY_TOKENS),
                    implode(', ', $spikes),
                ));
            }

            $budget = $entry['monthlyTokenBudget'];
            if ($budget > 0) {
                $usedShare = (int) round((($budget - $entry['budgetRemainingTokens']) / $budget) * 100);
                if ($usedShare >= AiBudgetPolicy::ALERT_UTILISATION_PERCENT) {
                    $io->warning(sprintf('This organization has used %d%% of its monthly budget.', $usedShare));
                }
            }
        }

        return Command::SUCCESS;
    }
}
