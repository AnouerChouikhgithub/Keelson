<?php

declare(strict_types=1);

namespace App\Summary;

/**
 * Immutable, already-authorized facts about one organization.
 *
 * This is the whole surface a model ever sees: integers, ISO dates, a currency
 * and at most five customer display names. There is deliberately no field that
 * could carry an email address, a phone number, a billing address, a note, an
 * invoice line or a member identity — those never enter this object, so they
 * cannot leak into a prompt or a response.
 *
 * Money is integer millimes, the representation the dashboard and the invoice
 * API already use. A key ending in `Millimes` is an amount; any other key is a
 * count. The fingerprint is what the summary cache and the prompt are keyed on:
 * the same facts produce the same key, and no fact means no cache entry.
 */
final readonly class WorkspaceFacts
{
    /** Millimes per currency unit; matches the decimal(14,3) invoice columns. */
    public const MONEY_SCALE = 3;

    /** The privacy ceiling: five display names, never an address book. */
    public const CUSTOMER_LIMIT = 5;

    /** Keys the payload fixes itself, so a fact may never shadow one. */
    private const PAYLOAD_KEYS = ['kind', 'role', 'locale', 'currency', 'customerNames'];

    /**
     * @param array<string, int>    $numbers       Counts, and amounts in millimes
     * @param array<string, string> $dates         ISO `YYYY-MM-DD` values
     * @param list<string>          $customerNames Display names only
     */
    public function __construct(
        public string $organizationId,
        public SummaryKind $kind,
        public string $role,
        public string $locale,
        public string $currency,
        public array $numbers,
        public array $dates = [],
        public array $customerNames = [],
    ) {
        if (!in_array($role, ['Owner', 'Admin', 'Member', 'Viewer'], true)) {
            throw new \InvalidArgumentException('A workspace fact set needs a known membership role.');
        }
        if (!in_array($locale, SummaryLocale::SUPPORTED, true)) {
            throw new \InvalidArgumentException('A workspace fact set needs a supported locale.');
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('A currency must be a three-letter ISO code.');
        }
        if (count($customerNames) > self::CUSTOMER_LIMIT) {
            throw new \InvalidArgumentException(sprintf('At most %d customer names may leave the database.', self::CUSTOMER_LIMIT));
        }

        foreach ($numbers as $key => $value) {
            if ('' === $key || in_array($key, self::PAYLOAD_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('The fact key "%s" is reserved or empty.', $key));
            }
            if ($value < 0) {
                throw new \InvalidArgumentException('Workspace facts are never negative.');
            }
        }
        foreach ($dates as $key => $value) {
            if (array_key_exists($key, $numbers) || in_array($key, self::PAYLOAD_KEYS, true)) {
                throw new \InvalidArgumentException(sprintf('The date key "%s" collides with another fact.', $key));
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                throw new \InvalidArgumentException('Workspace dates must be ISO calendar dates.');
            }
        }
        foreach ($customerNames as $name) {
            if ('' === trim($name)) {
                throw new \InvalidArgumentException('Customer display names must not be blank.');
            }
        }
    }

    /**
     * The `facts` object of the API response, and the canonical form the cache
     * fingerprint is computed from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'role' => $this->role,
            'locale' => $this->locale,
            'currency' => $this->currency,
        ] + $this->numbers + $this->dates + ['customerNames' => $this->customerNames];
    }

    /** Stable hash of every fact: same data, same key, no secret in the key. */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return array_filter(
            $this->numbers,
            static fn (string $key): bool => !str_ends_with($key, 'Millimes'),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string, int> */
    public function millimes(): array
    {
        return array_filter(
            $this->numbers,
            static fn (string $key): bool => str_ends_with($key, 'Millimes'),
            \ARRAY_FILTER_USE_KEY,
        );
    }
}
