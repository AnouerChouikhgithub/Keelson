<?php

declare(strict_types=1);

namespace App\Tests\Unit\Summary;

use App\Summary\GroundingCheck;
use App\Summary\SummaryKind;
use App\Summary\WorkspaceFacts;
use PHPUnit\Framework\TestCase;

/**
 * The grounding check is the guard between a plausible sentence and an invented
 * figure. These cases fix its vocabulary: exact facts, sums and differences,
 * percentages, and the three number notations the product writes in.
 */
final class GroundingCheckTest extends TestCase
{
    private GroundingCheck $check;

    protected function setUp(): void
    {
        $this->check = new GroundingCheck();
    }

    public function testAcceptsEveryNumberThatIsAFact(): void
    {
        self::assertTrue($this->check->verify(
            'There are 12 customers, 3 overdue invoices and 1,240.000 TND outstanding.',
            $this->facts(),
        ));
    }

    public function testRejectsAnInventedAmount(): void
    {
        self::assertFalse($this->check->verify('1,241.000 TND is outstanding.', $this->facts()));
    }

    public function testRejectsAnInventedCount(): void
    {
        self::assertFalse($this->check->verify('You have 13 customers.', $this->facts()));
    }

    public function testAcceptsASumOfTwoFacts(): void
    {
        // 12 customers + 3 overdue invoices.
        self::assertTrue($this->check->verify('15 items need a look.', $this->facts()));
    }

    public function testAcceptsADifferenceBetweenTwoFacts(): void
    {
        // 9 paid - 3 overdue.
        self::assertTrue($this->check->verify('6 invoices differ.', $this->facts()));
    }

    public function testAcceptsAFactAsAPercentageOfAnother(): void
    {
        // 3 of 12.
        self::assertTrue($this->check->verify('25% of the customers are late.', $this->facts()));
        self::assertTrue($this->check->verify('75% of the customers are not late.', $this->facts()));
    }

    public function testRejectsAnInventedPercentage(): void
    {
        self::assertFalse($this->check->verify('26% of the customers are late.', $this->facts()));
    }

    public function testReadsFrenchNotation(): void
    {
        $facts = $this->facts(locale: 'fr');

        self::assertTrue($this->check->verify('1 240,00 TND sont en attente.', $facts));
        self::assertTrue($this->check->verify('1.240,00 TND sont en attente.', $facts));
        self::assertFalse($this->check->verify('1 241,00 TND sont en attente.', $facts));
    }

    public function testReadsArabicIndicDigitsAndSeparators(): void
    {
        $facts = $this->facts(locale: 'ar');

        self::assertTrue($this->check->verify('المستحق ١٬٢٤٠٫٠٠٠ TND.', $facts));
        self::assertTrue($this->check->verify('٢٥٪ من العملاء متأخرون.', $facts));
        self::assertFalse($this->check->verify('١٬٢٤١٫٠٠٠ TND.', $facts));
    }

    /**
     * The same characters are a different number in each language, so the check
     * must not "accept either reading" — that would let a wrong figure through.
     */
    public function testTheSameTextMeansDifferentNumbersPerLocale(): void
    {
        $facts = ['invoiceCount' => 1234, 'outstandingMillimes' => 5000000];

        self::assertTrue($this->check->verify('1,234 invoices.', $this->facts(numbers: $facts, locale: 'en')));
        self::assertFalse($this->check->verify('1,234 invoices.', $this->facts(numbers: $facts, locale: 'fr')));
    }

    public function testQuotedCustomerNamesAndDatesAreNotFigures(): void
    {
        self::assertTrue($this->check->verify(
            'Acme 42 SARL is owed money as of 2026-11-17.',
            $this->facts(customerNames: ['Acme 42 SARL']),
        ));
    }

    public function testIdentifiersAreNotFigures(): void
    {
        self::assertTrue($this->check->verify(
            'Invoice INV-2026-77 is the oldest, unaffected by 9 paid invoices.',
            $this->facts(),
        ));
    }

    public function testAnAnswerWithoutNumbersIsGrounded(): void
    {
        self::assertTrue($this->check->verify('Nothing has changed this week.', $this->facts()));
    }

    /**
     * @param array<string, int>    $numbers
     * @param array<string, string> $dates
     * @param list<string>          $customerNames
     */
    private function facts(
        string $locale = 'en',
        array $numbers = [
            'customerCount' => 12,
            'overdueInvoiceCount' => 3,
            'paidInvoiceCount' => 9,
            'outstandingMillimes' => 1240000,
            'paidThisMonthMillimes' => 1234,
        ],
        array $dates = ['asOf' => '2026-11-17'],
        array $customerNames = ['Acme 42 SARL'],
    ): WorkspaceFacts {
        return new WorkspaceFacts(
            organizationId: '00000000-0000-4000-8000-000000000000',
            kind: SummaryKind::Overview,
            role: 'Owner',
            locale: $locale,
            currency: 'TND',
            numbers: $numbers,
            dates: $dates,
            customerNames: $customerNames,
        );
    }
}
