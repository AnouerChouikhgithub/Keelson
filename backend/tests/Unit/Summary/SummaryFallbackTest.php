<?php

declare(strict_types=1);

namespace App\Tests\Unit\Summary;

use App\Summary\SummaryFallback;
use App\Summary\SummaryKind;
use App\Summary\WorkspaceFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The fallback is what a user sees when the model is down or caught inventing a
 * number, so it has to be useful, deterministic and built only from the facts.
 */
final class SummaryFallbackTest extends TestCase
{
    /** @return iterable<string, array{SummaryKind, string}> */
    public static function kindsAndLocales(): iterable
    {
        foreach (SummaryKind::cases() as $kind) {
            foreach (['en', 'fr', 'ar'] as $locale) {
                yield $kind->value.' / '.$locale => [$kind, $locale];
            }
        }
    }

    #[DataProvider('kindsAndLocales')]
    public function testEveryKindHasADeterministicSentencePerLocale(SummaryKind $kind, string $locale): void
    {
        $fallback = new SummaryFallback();
        $facts = $this->facts($kind, $locale);

        $first = $fallback->text($facts);
        $second = $fallback->text($this->facts($kind, $locale));

        self::assertNotSame('', $first);
        self::assertSame($first, $second, 'The fallback must not depend on the clock or on any model.');
        self::assertStringNotContainsString('{', $first, 'Every placeholder must be filled.');
        self::assertStringNotContainsString('Fake AI answer', $first);
    }

    public function testTheOverviewSentenceQuotesTheOutstandingAmount(): void
    {
        $text = (new SummaryFallback())->text($this->facts(SummaryKind::Overview, 'en'));

        self::assertSame('Overview: 12 customers, 8 invoices, 3 overdue and 1,240.000 TND outstanding.', $text);
    }

    public function testFrenchUsesFrenchSeparators(): void
    {
        $text = (new SummaryFallback())->text($this->facts(SummaryKind::Receivables, 'fr'));

        self::assertStringContainsString('1 240,000 TND', $text);
        self::assertStringContainsString('échéance', $text);
    }

    public function testArabicUsesArabicText(): void
    {
        $text = (new SummaryFallback())->text($this->facts(SummaryKind::Activity, 'ar'));

        self::assertStringContainsString('أيام', $text);
        self::assertStringContainsString('فاتورة', $text);
    }

    private function facts(SummaryKind $kind, string $locale): WorkspaceFacts
    {
        $numbers = match ($kind) {
            SummaryKind::Overview => [
                'customerCount' => 12,
                'invoiceCount' => 8,
                'draftInvoiceCount' => 2,
                'sentInvoiceCount' => 3,
                'overdueInvoiceCount' => 3,
                'paidInvoiceCount' => 0,
                'outstandingMillimes' => 1240000,
                'overdueMillimes' => 500000,
                'paidThisMonthMillimes' => 0,
            ],
            SummaryKind::Receivables => [
                'openInvoiceCount' => 6,
                'overdueInvoiceCount' => 3,
                'dueSoonInvoiceCount' => 1,
                'outstandingMillimes' => 1240000,
                'overdueMillimes' => 500000,
                'dueSoonMillimes' => 90000,
            ],
            SummaryKind::Activity => [
                'windowDays' => 7,
                'invoicesCreated' => 4,
                'invoicesSent' => 3,
                'invoicesPaid' => 2,
                'paidMillimes' => 750000,
                'customersCreated' => 1,
            ],
        };

        $dates = ['asOf' => '2026-11-17'];
        if (SummaryKind::Receivables === $kind) {
            $dates['oldestDueDate'] = '2026-11-02';
        }
        if (SummaryKind::Activity === $kind) {
            $dates['windowStart'] = '2026-11-11';
        }

        return new WorkspaceFacts(
            organizationId: '00000000-0000-4000-8000-000000000000',
            kind: $kind,
            role: 'Member',
            locale: $locale,
            currency: 'TND',
            numbers: $numbers,
            dates: $dates,
            customerNames: ['Acme'],
        );
    }
}
