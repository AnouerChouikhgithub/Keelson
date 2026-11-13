<?php

declare(strict_types=1);

namespace App\Service;

final class InvoiceCalculator
{
    /**
     * Demo policy: TND only (3 decimal minor units / millimes); quantity has up to
     * three decimals, unit prices and discounts are millime amounts, tax rate is
     * a percentage with up to four decimals, tax rounds half-up to one millime.
     * Tax is calculated over max(0, subtotal - discount). No floats are used.
     *
     * @param list<array{description: mixed, quantity: mixed, unitPrice: mixed}> $items
     *
     * @return array{subtotal: string, taxAmount: string, discountAmount: string, total: string, taxRate: ?string, lineItems: list<array{description: string, quantity: string, unitPrice: string, lineTotal: string}>}
     */
    public function calculate(array $items, mixed $taxRate, mixed $discountAmount): array
    {
        if ([] === $items || count($items) > 100) {
            throw new \InvalidArgumentException('Invoice must contain between 1 and 100 line items.');
        }
        $taxRateUnits = null === $taxRate || '' === $taxRate ? null : $this->parseScaled($taxRate, 4, 'taxRate', 1000000);
        $discount = null === $discountAmount || '' === $discountAmount ? 0 : $this->parseScaled($discountAmount, 3, 'discountAmount', PHP_INT_MAX);
        $subtotal = 0;
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['description'] ?? null)) {
                throw new \InvalidArgumentException('Each line item requires a description, quantity, and unitPrice.');
            }
            $description = trim($item['description']);
            if ('' === $description || mb_strlen($description) > 240) {
                throw new \InvalidArgumentException('Line item description must contain 1 to 240 characters.');
            }
            $quantity = $this->parseScaled($item['quantity'] ?? null, 3, 'quantity', 100000000);
            if (0 === $quantity) {
                throw new \InvalidArgumentException('Line item quantity must be positive.');
            }
            $unitPrice = $this->parseScaled($item['unitPrice'] ?? null, 3, 'unitPrice', 999999999999999);
            $maxLineNumerator = 99999999999999 * 1000;
            $lineQuotient = intdiv($quantity, 1000);
            $lineRemainder = $quantity % 1000;
            if ($unitPrice > 0 && $lineQuotient > intdiv(99999999999999, $unitPrice)) {
                throw new \InvalidArgumentException('Invoice line total exceeds the supported maximum.');
            }
            $lineTotal = $lineQuotient * $unitPrice + $this->roundDivide($lineRemainder * $unitPrice, 1000);
            if ($lineTotal > 99999999999999) {
                throw new \InvalidArgumentException('Invoice line total exceeds the supported maximum.');
            }
            $subtotal += $lineTotal;
            if ($subtotal > 99999999999999) {
                throw new \InvalidArgumentException('Invoice subtotal exceeds the supported maximum.');
            }
            $normalized[] = [
                'description' => $description,
                'quantity' => $this->formatScaled($quantity, 3),
                'unitPrice' => $this->formatScaled($unitPrice, 3),
                'lineTotal' => $this->formatScaled($lineTotal, 3),
            ];
        }
        if ($discount > $subtotal) {
            throw new \InvalidArgumentException('Discount cannot exceed the subtotal.');
        }
        $taxable = $subtotal - $discount;
        // Percent rate is stored in 1/10,000 percentage points; divide by 1,000,000 for a ratio.
        // Tax rate is a percentage stored in 1/10,000 percentage points:
        // dividing by 1,000,000 converts it to a ratio. Compute the product
        // quotient/remainder to avoid overflowing for large invoice values.
        $taxDenominator = 1000000;
        $taxQuotient = intdiv($taxable, $taxDenominator);
        if (null !== $taxRateUnits && $taxQuotient > intdiv(99999999999999, max(1, $taxRateUnits))) {
            throw new \InvalidArgumentException('Invoice tax exceeds the supported maximum.');
        }
        $tax = null === $taxRateUnits ? 0 : $taxQuotient * $taxRateUnits
            + $this->roundDivide(($taxable % $taxDenominator) * $taxRateUnits, $taxDenominator);
        if ($taxable + $tax > 99999999999999) {
            throw new \InvalidArgumentException('Invoice total exceeds the supported maximum.');
        }

        return [
            'subtotal' => $this->formatScaled($subtotal, 3),
            'taxAmount' => $this->formatScaled($tax, 3),
            'discountAmount' => $this->formatScaled($discount, 3),
            'total' => $this->formatScaled($taxable + $tax, 3),
            'taxRate' => null === $taxRateUnits ? null : $this->formatScaled($taxRateUnits, 4),
            'lineItems' => $normalized,
        ];
    }

    private function parseScaled(mixed $value, int $scale, string $field, int $max): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be a decimal string.', $field));
        }
        $value = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf('%s must be a non-negative decimal without exponent notation.', $field));
        }
        $fraction = $matches[2] ?? '';
        if (strlen($fraction) > $scale) {
            throw new \InvalidArgumentException(sprintf('%s supports at most %d decimal places.', $field, $scale));
        }
        $fraction = str_pad($fraction, $scale, '0');
        $factor = 10 ** $scale;
        $whole = (int) $matches[1];
        if ($whole > intdiv($max, $factor)) {
            throw new \InvalidArgumentException(sprintf('%s exceeds the supported maximum.', $field));
        }
        $scaled = $whole * $factor + ('' === $fraction ? 0 : (int) $fraction);
        if ($scaled > $max) {
            throw new \InvalidArgumentException(sprintf('%s exceeds the supported maximum.', $field));
        }

        return $scaled;
    }

    private function roundDivide(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    private function formatScaled(int $value, int $scale): string
    {
        return intdiv($value, 10 ** $scale).'.'.str_pad((string) ($value % (10 ** $scale)), $scale, '0', STR_PAD_LEFT);
    }
}
