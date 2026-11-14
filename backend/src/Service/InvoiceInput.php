<?php

declare(strict_types=1);

namespace App\Service;

final class InvoiceInput
{
    /** @return array{customerId: string, issueDate: \DateTimeImmutable, dueDate: \DateTimeImmutable, calculation: array} */
    public function parse(array $data, InvoiceCalculator $calculator): array
    {
        foreach (['customerId', 'issueDate', 'dueDate', 'currency', 'lineItems'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new \InvalidArgumentException(sprintf('%s is required.', $required));
            }
        }
        if (!is_string($data['customerId']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $data['customerId'])) {
            throw new \InvalidArgumentException('customerId must be a UUID.');
        }
        if ('TND' !== $data['currency']) {
            throw new \InvalidArgumentException('The invoicing demo supports TND only.');
        }

        return [
            'customerId' => $data['customerId'],
            'issueDate' => $this->date($data['issueDate'], 'issueDate'),
            'dueDate' => $this->date($data['dueDate'], 'dueDate'),
            'calculation' => $calculator->calculate(
                is_array($data['lineItems']) ? $data['lineItems'] : [],
                $data['taxRate'] ?? null,
                $data['discountAmount'] ?? null,
            ),
        ];
    }

    private function date(mixed $value, string $field): \DateTimeImmutable
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf('%s must use YYYY-MM-DD format.', $field));
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('%s must be a valid calendar date in YYYY-MM-DD format.', $field));
        }

        return $date;
    }
}
