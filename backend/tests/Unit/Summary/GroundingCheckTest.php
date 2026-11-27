<?php

declare(strict_types=1);

namespace App\Tests\Unit\Summary;

use App\Ai\Safety\PromptBuilder;
use App\Summary\GroundingCheck;
use App\Summary\SummaryKind;
use App\Summary\SummaryPromptFactory;
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

    // --- Spelled-out numbers (F-04) -----------------------------------------

    public function testAcceptsASpelledOutNumberThatIsAFact(): void
    {
        self::assertTrue($this->check->verify('Twelve customers are registered.', $this->facts()));
        // Composed English: one hundred is 100, twelve thousand is 12 000.
        self::assertTrue($this->check->verify('One hundred invoices.', $this->facts(numbers: ['invoiceCount' => 100])));
        self::assertTrue($this->check->verify('Twelve thousand invoices.', $this->facts(numbers: ['invoiceCount' => 12000])));
    }

    public function testRejectsASpelledOutNumberThatContradictsTheFacts(): void
    {
        // The facts say three overdue invoices, not five.
        self::assertFalse($this->check->verify('Five invoices are overdue.', $this->facts()));
        self::assertFalse($this->check->verify('Ninety invoices are overdue.', $this->facts()));
    }

    public function testWordsAndDigitsInOneAnswerAreEachChecked(): void
    {
        self::assertTrue($this->check->verify(
            '12 customers, three overdue invoices and 1,240.000 TND outstanding.',
            $this->facts(),
        ));
        // The digit is a fact and the word is not: one bad claim fails the answer.
        self::assertFalse($this->check->verify(
            '12 customers and five overdue invoices.',
            $this->facts(),
        ));
    }

    public function testANumberWordInsideAnotherWordIsNotAClaim(): void
    {
        // "one" inside "someone", "an" inside "plan", "ten" inside "listen".
        self::assertTrue($this->check->verify('Someone will plan the work and listen.', $this->facts()));
    }

    // --- French -------------------------------------------------------------

    public function testReadsFrenchNumberWords(): void
    {
        $facts = $this->facts(locale: 'fr');

        self::assertTrue($this->check->verify('Douze clients et trois factures en retard.', $facts));
        self::assertFalse($this->check->verify('Cinq factures sont en retard.', $facts));
        // quatre-vingt-dix is 90, soixante-dix is 70, vingt et un is 21.
        self::assertTrue($this->check->verify('Quatre-vingt-dix factures.', $this->facts(locale: 'fr', numbers: ['invoiceCount' => 90])));
        self::assertFalse($this->check->verify('Quatre-vingt-onze factures.', $this->facts(locale: 'fr', numbers: ['invoiceCount' => 90])));
        self::assertTrue($this->check->verify('Vingt et un clients.', $this->facts(locale: 'fr', numbers: ['customerCount' => 21])));
        self::assertTrue($this->check->verify('Deux cents factures.', $this->facts(locale: 'fr', numbers: ['invoiceCount' => 200])));
    }

    public function testTheFrenchArticleIsNotAClaim(): void
    {
        // "un client" means "a customer"; it is not the figure 1.
        self::assertTrue($this->check->verify('Un client a payé.', $this->facts(locale: 'fr')));
    }

    // --- Arabic -------------------------------------------------------------

    public function testReadsArabicNumberWordsWithDiacriticsAndTatweel(): void
    {
        $facts = $this->facts(locale: 'ar');

        // "twelve customers and three overdue invoices"
        self::assertTrue($this->check->verify('هناك اثنا عشر عميلًا وثلاث فواتير متأخرة.', $facts));
        self::assertFalse($this->check->verify('خمس فواتير متأخرة.', $facts));
        // Diacritics and tatweel are folded before the word is read.
        self::assertTrue($this->check->verify('ثَلَاثُ فواتير متأخرة.', $facts));
        self::assertTrue($this->check->verify('ثـــلاث فواتير متأخرة.', $facts));
        // خمسة وعشرون is 25.
        self::assertTrue($this->check->verify(
            'خمسة وعشرون عميلًا.',
            $this->facts(locale: 'ar', numbers: ['customerCount' => 25]),
        ));
        self::assertFalse($this->check->verify(
            'خمسة وعشرون عميلًا.',
            $this->facts(locale: 'ar', numbers: ['customerCount' => 24]),
        ));
    }

    // --- Vague quantities ----------------------------------------------------

    public function testRejectsVagueQuantitiesInEveryLanguage(): void
    {
        self::assertFalse($this->check->verify('Almost half of the balance is overdue.', $this->facts()));
        self::assertFalse($this->check->verify('Most invoices are paid.', $this->facts()));
        self::assertFalse($this->check->verify('Nearly all customers are late.', $this->facts()));
        self::assertFalse($this->check->verify(
            'Environ la moitié du solde est en retard.',
            $this->facts(locale: 'fr'),
        ));
        self::assertFalse($this->check->verify(
            'La plupart des factures sont payées.',
            $this->facts(locale: 'fr'),
        ));
        self::assertFalse($this->check->verify('نصف الرصيد متأخر.', $this->facts(locale: 'ar')));
        self::assertFalse($this->check->verify('معظم الفواتير مدفوعة.', $this->facts(locale: 'ar')));
    }

    public function testAVagueWordInsideACustomerNameIsNotAClaim(): void
    {
        // The name is quoted data, not a quantity claim about the workspace.
        self::assertTrue($this->check->verify(
            'Half Price Ltd still owes 1,240.000 TND.',
            $this->facts(customerNames: ['Half Price Ltd']),
        ));
    }

    /**
     * Live captures, fixture data only. Every one of these correct summaries was
     * rejected before the fix: localized dates left `2026` to be read as a figure, and
     * a raw-millimes restatement did not match the currency-unit fact.
     */
    public static function liveCaptures(): iterable
    {
        yield 'fr localized date' => ['fr', 'Au 25 novembre 2026, vous avez 7 clients et 18 factures. Deux d\'entre elles sont en brouillon, cinq ont été envoyées, trois sont en retard et huit ont été payées. Le total des montants en attente est de 11956600 millimes, dont 6922200 millimes en retard.'];
        yield 'fr iso date with raw millimes' => ['fr', 'Au 2026-11-25, vous gérez 7 clients. Il y a 18 factures en tout, dont 2 sont en brouillon, 5 ont été envoyées, 3 sont en retard et 8 ont été payées. Les montants en attente sont de 11956600 millimes, dont 6922200 millimes sont en retard.'];
        yield 'ar non-breaking-hyphen date' => ['ar', "في 2026\u{2011}11\u{2011}25، هناك 7 عملاء و18 فاتورة، من بينها 2 مسودة، 5 مرسلة، 3 متأخرة، 8 مدفوعة. إجمالي المستحقات 11956600 ملليم، مع متأخرات 6922200 ملليم. لا توجد دفعات هذا الشهر."];
        yield 'ar currency units' => ['ar', 'اعتباراً من 2026-11-25 لديك 7 عملاء و18 فاتورة. يوجد 2 مسودة، 5 مرسلة، 3 متأخرة، و8 مدفوعة. الرصيد المتبقي هو 11956.600 TND، الرصيد المتأخر هو 6922.200 TND، والمدفوع هذا الشهر هو 0 TND.'];
        yield 'ar localized date' => ['ar', 'التاريخ 25 نوفمبر 2026. يوجد 7 عملاء، 18 فاتورة، منها 2 مسودة، 5 مرسلة، 3 متأخرة، 8 مدفوعة. المبلغ المتبقي 11956600 ملليم، المتأخر 6922200 ملليم، والمدفوع هذا الشهر 0 ملليم.'];
        yield 'fr dot decimal with a date' => ['fr', "Vous gérez un espace de travail en tant que propriétaire depuis le 2026\u{2011}11\u{2011}25. Il compte 7 clients et 18 factures, dont 2 sont en brouillon, 5 ont été envoyées, 3 sont en retard et 8 ont été payées. Le solde hors délai s'élève à 11956.600 TND, dont 6922.200 TND sont en retard."];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('liveCaptures')]
    public function testALiveCaptureOfACorrectSummaryIsAccepted(string $locale, string $text): void
    {
        self::assertNull($this->check->rejectionReason($text, $this->capturedFacts($locale)));
    }

    public function testARawMillimesAmountWithoutTheMillimeWordIsStillRejected(): void
    {
        self::assertSame(
            'ungrounded_number',
            $this->check->rejectionReason('Le solde est de 11956600 TND.', $this->capturedFacts('fr')),
        );
    }

    public function testAnInventedFigureInFrenchIsRejected(): void
    {
        self::assertSame(
            'ungrounded_number',
            $this->check->rejectionReason('Vous avez 42 clients et 11956.600 TND en attente.', $this->capturedFacts('fr')),
        );
    }

    public function testAVagueQuantityInArabicIsRejected(): void
    {
        self::assertSame(
            'vague_quantity',
            $this->check->rejectionReason('لديك حوالي 7 عملاء.', $this->capturedFacts('ar')),
        );
    }

    public function testACorrectDateInAnotherNotationDoesNotHideAnInventedFigure(): void
    {
        self::assertSame(
            'ungrounded_number',
            $this->check->rejectionReason('Le 25 novembre 2026, vous avez 77 clients.', $this->capturedFacts('fr')),
        );
    }

    public function testTheFrenchPromptUsesTheFrenchDecimalComma(): void
    {
        $prompt = (new SummaryPromptFactory(new PromptBuilder()))->prompt($this->capturedFacts('fr'));

        self::assertStringContainsString('number.outstanding: 11956,600', $prompt);
        self::assertStringContainsString('number.overdue: 6922,200', $prompt);
    }

    private function capturedFacts(string $locale): WorkspaceFacts
    {
        return $this->facts(
            locale: $locale,
            numbers: [
                'customerCount' => 7,
                'invoiceCount' => 18,
                'draftInvoiceCount' => 2,
                'sentInvoiceCount' => 5,
                'overdueInvoiceCount' => 3,
                'paidInvoiceCount' => 8,
                'outstandingMillimes' => 11956600,
                'overdueMillimes' => 6922200,
                'paidThisMonthMillimes' => 0,
            ],
            dates: ['asOf' => '2026-11-25'],
            customerNames: ['Atlas Harbour Supplies (fixture)', 'Cedar and Pine Studio (fixture)'],
        );
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
