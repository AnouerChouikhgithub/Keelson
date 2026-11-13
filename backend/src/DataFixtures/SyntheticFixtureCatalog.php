<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Synthetic, non-production demo data for local development and tests.
 *
 * Every record here is fake: the issuer is a fixture-only URL, emails use the
 * reserved `example.test` domain, and no credentials exist for these subjects.
 * The loader resolves records by natural keys, so this catalog can be seeded
 * repeatedly without creating duplicates.
 */
final class SyntheticFixtureCatalog
{
    public const ISSUER = 'https://fixtures.example.test/oidc';

    /**
     * @return array{
     *     principals: list<array{key: string, subject: string, displayName: string, email: string}>,
     *     organizations: list<array{key: string, name: string, subdomain: string}>,
     *     memberships: list<array{organization: string, principal: string, role: string}>,
     *     customers: list<array{key: string, organization: string, name: string, email: string, phone: ?string, billingAddress: ?array{street: string, city: string, postalCode: string, countryCode: string}}>,
     *     invoices: list<array{key: string, organization: string, customer: string, number: string, status: string, issueOffsetDays: int, dueOffsetDays: int, lineItems: list<array{description: string, quantity: string, unitPrice: string}>, taxRate: ?string, discountAmount: ?string}>
     * }
     */
    public function definitions(): array
    {
        return [
            'principals' => [
                ['key' => 'owner-a', 'subject' => 'fixture-owner-a', 'displayName' => 'Amina Owner (fixture)', 'email' => 'owner-a@example.test'],
                ['key' => 'admin-a', 'subject' => 'fixture-admin-a', 'displayName' => 'Youssef Admin (fixture)', 'email' => 'admin-a@example.test'],
                ['key' => 'member-a', 'subject' => 'fixture-member-a', 'displayName' => 'Sami Member (fixture)', 'email' => 'member-a@example.test'],
                ['key' => 'viewer-b', 'subject' => 'fixture-viewer-b', 'displayName' => 'Leila Viewer (fixture)', 'email' => 'viewer-b@example.test'],
            ],
            'organizations' => [
                ['key' => 'northwind', 'name' => 'Northwind Demo (fixture)', 'subdomain' => 'fixture-northwind'],
                ['key' => 'contoso', 'name' => 'Contoso Demo (fixture)', 'subdomain' => 'fixture-contoso'],
            ],
            'memberships' => [
                ['organization' => 'northwind', 'principal' => 'owner-a', 'role' => 'Owner'],
                ['organization' => 'northwind', 'principal' => 'admin-a', 'role' => 'Admin'],
                ['organization' => 'northwind', 'principal' => 'member-a', 'role' => 'Member'],
                ['organization' => 'contoso', 'principal' => 'viewer-b', 'role' => 'Viewer'],
                // One principal belongs to both organizations so tenant switching can be exercised.
                ['organization' => 'contoso', 'principal' => 'member-a', 'role' => 'Member'],
            ],
            'customers' => [
                ['key' => 'acme', 'organization' => 'northwind', 'name' => 'Acme Test Customer', 'email' => 'acme@example.test', 'phone' => '+21600000001', 'billingAddress' => ['street' => '1 Rue de Test', 'city' => 'Tunis', 'postalCode' => '1000', 'countryCode' => 'tn']],
                ['key' => 'beta', 'organization' => 'northwind', 'name' => 'Beta Test Customer', 'email' => 'beta@example.test', 'phone' => null, 'billingAddress' => null],
                ['key' => 'gamma', 'organization' => 'contoso', 'name' => 'Gamma Test Customer', 'email' => 'gamma@example.test', 'phone' => null, 'billingAddress' => null],
                ['key' => 'delta', 'organization' => 'contoso', 'name' => 'Delta Test Customer', 'email' => 'delta@example.test', 'phone' => null, 'billingAddress' => null],
            ],
            'invoices' => [
                ['key' => 'northwind-draft', 'organization' => 'northwind', 'customer' => 'acme', 'number' => '2026-900001', 'status' => 'draft', 'issueOffsetDays' => -5, 'dueOffsetDays' => 10, 'lineItems' => [['description' => 'Fixture drafting work', 'quantity' => '2.000', 'unitPrice' => '150.000']], 'taxRate' => '19.0000', 'discountAmount' => '5.000'],
                ['key' => 'northwind-sent', 'organization' => 'northwind', 'customer' => 'beta', 'number' => '2026-900002', 'status' => 'sent', 'issueOffsetDays' => -10, 'dueOffsetDays' => 5, 'lineItems' => [['description' => 'Fixture delivered work', 'quantity' => '1.000', 'unitPrice' => '300.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
                ['key' => 'northwind-overdue', 'organization' => 'northwind', 'customer' => 'acme', 'number' => '2026-900003', 'status' => 'overdue', 'issueOffsetDays' => -45, 'dueOffsetDays' => -15, 'lineItems' => [['description' => 'Fixture overdue work', 'quantity' => '3.000', 'unitPrice' => '120.500']], 'taxRate' => null, 'discountAmount' => null],
                ['key' => 'northwind-paid', 'organization' => 'northwind', 'customer' => 'beta', 'number' => '2026-900004', 'status' => 'paid', 'issueOffsetDays' => -60, 'dueOffsetDays' => -30, 'lineItems' => [['description' => 'Fixture settled work', 'quantity' => '1.000', 'unitPrice' => '90.000']], 'taxRate' => '7.0000', 'discountAmount' => null],
                ['key' => 'contoso-draft', 'organization' => 'contoso', 'customer' => 'gamma', 'number' => '2026-910001', 'status' => 'draft', 'issueOffsetDays' => -2, 'dueOffsetDays' => 28, 'lineItems' => [['description' => 'Other tenant draft', 'quantity' => '1.000', 'unitPrice' => '75.000']], 'taxRate' => null, 'discountAmount' => null],
                ['key' => 'contoso-sent', 'organization' => 'contoso', 'customer' => 'delta', 'number' => '2026-910002', 'status' => 'sent', 'issueOffsetDays' => -7, 'dueOffsetDays' => 21, 'lineItems' => [['description' => 'Other tenant sent work', 'quantity' => '4.000', 'unitPrice' => '25.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ],
        ];
    }
}
