<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\InvoiceCalculator;
use PHPUnit\Framework\TestCase;

final class InvoiceCalculatorTest extends TestCase
{
    private InvoiceCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new InvoiceCalculator();
    }

    public function testCalculatesTndTotalsWithMillimeRounding(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'Service', 'quantity' => '1.500', 'unitPrice' => '10.001'],
            ['description' => 'Parts', 'quantity' => '2', 'unitPrice' => '3.333'],
        ], '19.0000', '1.000');

        self::assertSame('21.668', $result['subtotal']);
        self::assertSame('3.927', $result['taxAmount']);
        self::assertSame('1.000', $result['discountAmount']);
        self::assertSame('24.595', $result['total']);
        self::assertSame('19.0000', $result['taxRate']);
        self::assertSame('15.002', $result['lineItems'][0]['lineTotal']);
    }

    public function testUsesHalfUpRoundingForTaxAtOneMillime(): void
    {
        $result = $this->calculator->calculate([
            ['description' => 'One millime', 'quantity' => '1', 'unitPrice' => '0.001'],
        ], '50', null);

        self::assertSame('0.001', $result['taxAmount']);
    }

    public function testRejectsExcessPrecisionAndScientificNotation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate([
            ['description' => 'Invalid', 'quantity' => '1.0001', 'unitPrice' => '1.000'],
        ], null, null);
    }

    public function testRejectsDiscountAboveSubtotal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate([
            ['description' => 'Service', 'quantity' => '1', 'unitPrice' => '1.000'],
        ], null, '1.001');
    }

    public function testRejectsMalformedDecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->calculator->calculate([
            ['description' => 'Service', 'quantity' => '1e2', 'unitPrice' => '1.000'],
        ], null, null);
    }
}
