<?php

declare(strict_types=1);

namespace App\Tests\Unit\Summary;

use App\Summary\SummaryKind;
use App\Summary\SummaryLocale;
use App\Summary\WorkspaceFacts;
use PHPUnit\Framework\TestCase;

/**
 * The value object is the privacy and correctness boundary: if it cannot hold a
 * contact detail or a sixth name, no later code can send one.
 */
final class WorkspaceFactsTest extends TestCase
{
    public function testThePayloadCarriesOnlyFactsAndNoTenantId(): void
    {
        $facts = $this->facts();

        $payload = $facts->toArray();

        self::assertSame('overview', $payload['kind']);
        self::assertSame('Owner', $payload['role']);
        self::assertSame('TND', $payload['currency']);
        self::assertSame(1240000, $payload['outstandingMillimes']);
        self::assertSame('2026-11-17', $payload['asOf']);
        self::assertSame(['Acme', 'Beta'], $payload['customerNames']);
        self::assertArrayNotHasKey('organizationId', $payload);
    }

    public function testMillimesAndCountsAreSeparatedWithoutLosingIntegers(): void
    {
        $facts = $this->facts();

        self::assertSame(['outstandingMillimes' => 1240000], $facts->millimes());
        self::assertSame(['customerCount' => 12], $facts->counts());
        self::assertIsInt($facts->millimes()['outstandingMillimes']);
    }

    public function testTheFingerprintIsStableAndChangesWithAFact(): void
    {
        $first = $this->facts()->fingerprint();

        self::assertSame($first, $this->facts()->fingerprint());
        self::assertNotSame($first, $this->facts(numbers: ['customerCount' => 13, 'outstandingMillimes' => 1240000])->fingerprint());
        self::assertNotSame($first, $this->facts(customerNames: ['Acme'])->fingerprint());
    }

    public function testASixthCustomerNameIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facts(customerNames: ['A', 'B', 'C', 'D', 'E', 'F']);
    }

    public function testReservedAndNegativeFactsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facts(numbers: ['kind' => 1]);
    }

    public function testANegativeAmountIsNotAFact(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facts(numbers: ['outstandingMillimes' => -1]);
    }

    public function testOnlySupportedLocalesAndIsoCurrenciesAreAccepted(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new WorkspaceFacts(
            organizationId: '00000000-0000-4000-8000-000000000000',
            kind: SummaryKind::Overview,
            role: 'Owner',
            locale: 'de',
            currency: 'TND',
            numbers: [],
        );
    }

    public function testDatesMustBeIsoAndMustNotCollideWithNumbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->facts(dates: ['asOf' => '17/11/2026']);
    }

    public function testSupportedLocalesAreTheThreeTheProductShips(): void
    {
        self::assertSame(['en', 'fr', 'ar'], SummaryLocale::SUPPORTED);
    }

    /**
     * @param array<string, int>    $numbers
     * @param array<string, string> $dates
     * @param list<string>          $customerNames
     */
    private function facts(
        array $numbers = ['customerCount' => 12, 'outstandingMillimes' => 1240000],
        array $dates = ['asOf' => '2026-11-17'],
        array $customerNames = ['Acme', 'Beta'],
    ): WorkspaceFacts {
        return new WorkspaceFacts(
            organizationId: '00000000-0000-4000-8000-000000000000',
            kind: SummaryKind::Overview,
            role: 'Owner',
            locale: 'en',
            currency: 'TND',
            numbers: $numbers,
            dates: $dates,
            customerNames: $customerNames,
        );
    }
}
