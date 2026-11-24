<?php

declare(strict_types=1);

namespace App\Tests\Ai\Eval;

use App\Summary\GroundingCheck;
use App\Summary\SummaryFallback;
use App\Summary\SummaryKind;
use App\Summary\WorkspaceFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The grounding evaluation: the claims a summary may and may not make.
 *
 * `GroundingCheck` is the boundary between a plausible sentence and an invented
 * figure, so the eval pins the boundary itself rather than the prose around it:
 * facts written as digits, facts written in words, a word that contradicts a
 * fact, and a vague quantity that no fact could ever prove — in all three
 * languages the product writes in.
 *
 * The last case is the property that matters in production: whatever the check
 * refuses becomes the deterministic template, so the template must always pass
 * the same check. Otherwise a refused answer would be replaced by text the
 * system itself would refuse.
 *
 * In the `ai-eval` group for the same reason as the guide and summary suites:
 * this is the data the real-provider workflow runs.
 */
#[Group('ai-eval')]
final class GroundingEvalTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function claims(): iterable
    {
        // English: digits, words, a contradiction, vague wording.
        yield 'en digits that a fact supports' => ['en', 'You have 3 invoices and 9 customers.', true];
        yield 'en word number that a fact supports' => ['en', 'You have nine customers.', true];
        yield 'en word number that contradicts a fact' => ['en', 'You have forty-two invoices.', false];
        yield 'en mixed digits and words' => ['en', '9 customers, of which 1 is overdue.', true];
        yield 'en vague half' => ['en', 'Half of the invoices are unpaid.', false];
        yield 'en vague most' => ['en', 'Most customers have paid.', false];

        // French: composition (quatre-vingt-dix), the article, vague wording.
        yield 'fr digits that a fact supports' => ['fr', 'Neuf clients et trois factures.', true];
        yield 'fr word number that contradicts a fact' => ['fr', 'Quarante-deux factures sont en retard.', false];
        yield 'fr article is not a claim' => ['fr', 'Un client a payé.', true];
        yield 'fr vague presque' => ['fr', 'Presque toutes les factures sont payées.', false];

        // Arabic: gender variants, diacritics, vague wording.
        yield 'ar digits that a fact supports' => ['ar', 'لديك ٣ فواتير و٩ عملاء.', true];
        yield 'ar word number that a fact supports' => ['ar', 'لديك تسعة عملاء وثلاث فواتير.', true];
        yield 'ar word number that contradicts a fact' => ['ar', 'اثنان وأربعون فاتورة متأخرة.', false];
        yield 'ar diacritics do not hide a claim' => ['ar', 'ثَلَاثُ فواتير متأخرة.', true];
        yield 'ar vague نصف' => ['ar', 'نصف الرصيد متأخر.', false];
        yield 'ar vague معظم' => ['ar', 'معظم الفواتير مدفوعة.', false];
    }

    #[DataProvider('claims')]
    public function testTheClaimIsProvenOrRefused(string $locale, string $text, bool $accepted): void
    {
        self::assertSame(
            $accepted,
            (new GroundingCheck())->verify($text, $this->facts($locale)),
            sprintf('%s answer "%s"', $accepted ? 'accepted' : 'refused', $text),
        );
    }

    public function testTheDeterministicTemplateAlwaysSurvivesTheCheck(): void
    {
        $check = new GroundingCheck();
        $fallback = new SummaryFallback();

        foreach (['en', 'fr', 'ar'] as $locale) {
            foreach (SummaryKind::cases() as $kind) {
                $facts = $this->facts($locale, $kind);
                self::assertTrue(
                    $check->verify($fallback->text($facts), $facts),
                    sprintf('The %s %s template must pass the check that sends answers to it.', $locale, $kind->value),
                );
            }
        }
    }

    private function facts(string $locale, SummaryKind $kind = SummaryKind::Overview): WorkspaceFacts
    {
        return new WorkspaceFacts(
            organizationId: '00000000-0000-4000-8000-000000000000',
            kind: $kind,
            role: 'Owner',
            locale: $locale,
            currency: 'TND',
            numbers: [
                'customerCount' => 9,
                'invoiceCount' => 3,
                'draftInvoiceCount' => 1,
                'sentInvoiceCount' => 1,
                'overdueInvoiceCount' => 1,
                'paidInvoiceCount' => 1,
                'outstandingMillimes' => 203250,
                'overdueMillimes' => 51000,
                'paidThisMonthMillimes' => 78500,
                'openInvoiceCount' => 2,
                'dueSoonInvoiceCount' => 1,
                'dueSoonMillimes' => 12250,
                'windowDays' => 7,
                'invoicesCreated' => 3,
                'invoicesSent' => 2,
                'invoicesPaid' => 1,
                'paidMillimes' => 78500,
                'customersCreated' => 9,
            ],
            dates: ['asOf' => '2026-11-17'],
            customerNames: ['Acme Holdings'],
        );
    }
}
