<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * What the client gets when no usable model answer exists.
 *
 * Three sentences, one per kind, written from the facts alone and identical on
 * every call for the same facts and locale — no timestamp, no randomness, no
 * model. That is what makes it safe to return with `fallback: true` when the
 * provider is down, the answer was empty, or {@see GroundingCheck} rejected a
 * figure the facts cannot support.
 *
 * Every number here is copied from the facts; nothing is computed.
 */
final class SummaryFallback
{
    public function text(WorkspaceFacts $facts): string
    {
        return match ($facts->kind) {
            SummaryKind::Overview => $this->overview($facts),
            SummaryKind::Receivables => $this->receivables($facts),
            SummaryKind::Activity => $this->activity($facts),
        };
    }

    private function overview(WorkspaceFacts $facts): string
    {
        $numbers = $facts->numbers;
        $args = [
            $this->count($numbers['customerCount'] ?? 0, $facts->locale),
            $this->count($numbers['invoiceCount'] ?? 0, $facts->locale),
            $this->count($numbers['overdueInvoiceCount'] ?? 0, $facts->locale),
            $this->money($numbers['outstandingMillimes'] ?? 0, $facts->locale),
            $facts->currency,
        ];

        return match ($facts->locale) {
            'fr' => vsprintf('Vue d\'ensemble : %s clients, %s factures, %s en retard et %s %s en attente.', $args),
            'ar' => vsprintf('نظرة عامة: %s عميل، %s فاتورة، %s متأخرة و%s %s مستحقة.', $args),
            default => vsprintf('Overview: %s customers, %s invoices, %s overdue and %s %s outstanding.', $args),
        };
    }

    private function receivables(WorkspaceFacts $facts): string
    {
        $numbers = $facts->numbers;
        $args = [
            $this->money($numbers['outstandingMillimes'] ?? 0, $facts->locale),
            $facts->currency,
            $this->count($numbers['openInvoiceCount'] ?? 0, $facts->locale),
            $this->money($numbers['overdueMillimes'] ?? 0, $facts->locale),
            $facts->currency,
            $this->count($numbers['overdueInvoiceCount'] ?? 0, $facts->locale),
        ];

        $text = match ($facts->locale) {
            'fr' => vsprintf('%s %s en attente sur %s factures ouvertes ; %s %s sont en retard (%s factures).', $args),
            'ar' => vsprintf('%s %s مستحقة على %s فاتورة مفتوحة؛ منها %s %s متأخرة (%s فواتير).', $args),
            default => vsprintf('%s %s is outstanding across %s open invoices; %s %s of it is overdue (%s invoices).', $args),
        };

        $oldest = $facts->dates['oldestDueDate'] ?? null;
        if (!is_string($oldest)) {
            return $text;
        }

        return $text.' '.match ($facts->locale) {
            'fr' => sprintf('L\'échéance la plus ancienne est le %s.', $oldest),
            'ar' => sprintf('أقدم تاريخ استحقاق هو %s.', $oldest),
            default => sprintf('The oldest due date is %s.', $oldest),
        };
    }

    private function activity(WorkspaceFacts $facts): string
    {
        $numbers = $facts->numbers;
        $args = [
            $this->count($numbers['windowDays'] ?? 0, $facts->locale),
            $this->count($numbers['invoicesCreated'] ?? 0, $facts->locale),
            $this->count($numbers['invoicesSent'] ?? 0, $facts->locale),
            $this->count($numbers['invoicesPaid'] ?? 0, $facts->locale),
            $this->count($numbers['customersCreated'] ?? 0, $facts->locale),
        ];

        return match ($facts->locale) {
            'fr' => vsprintf('Ces %s derniers jours : %s factures créées, %s envoyées, %s payées et %s clients ajoutés.', $args),
            'ar' => vsprintf('خلال آخر %s أيام: %s فاتورة منشأة، %s مرسلة، %s مدفوعة و%s عميل جديد.', $args),
            default => vsprintf('Last %s days: %s invoices created, %s sent, %s paid and %s customers added.', $args),
        };
    }

    /** Grouped with the locale's separators; never a float. */
    private function count(int $value, string $locale): string
    {
        return number_format($value, 0, '.', $this->thousands($locale));
    }

    /** Millimes to a currency amount, three decimals, integer arithmetic only. */
    private function money(int $millimes, string $locale): string
    {
        $whole = intdiv($millimes, 10 ** WorkspaceFacts::MONEY_SCALE);
        $fraction = $millimes % 10 ** WorkspaceFacts::MONEY_SCALE;

        return number_format($whole, 0, '.', $this->thousands($locale))
            .$this->decimal($locale)
            .str_pad((string) $fraction, WorkspaceFacts::MONEY_SCALE, '0', \STR_PAD_LEFT);
    }

    private function thousands(string $locale): string
    {
        return match ($locale) {
            'fr' => ' ',
            'ar' => '٬',
            default => ',',
        };
    }

    private function decimal(string $locale): string
    {
        return match ($locale) {
            'fr' => ',',
            'ar' => '٫',
            default => '.',
        };
    }
}
