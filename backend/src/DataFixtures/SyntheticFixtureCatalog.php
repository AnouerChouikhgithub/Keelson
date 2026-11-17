<?php

declare(strict_types=1);

namespace App\DataFixtures;

/**
 * Synthetic, non-production demo data for local development and tests.
 *
 * Every record here is fake: emails use the reserved `example.test` domain and
 * the shared password is documented on purpose so a developer can sign in
 * locally. It must never be reused anywhere real — the fixture loader refuses
 * any target that is not an obvious local development or test database.
 *
 * The five accounts cover the role matrix on purpose (Owner, Admin, Member in
 * two organizations, Viewer, and one account whose address was never
 * confirmed), so every authorization and verification state can be reached from
 * a browser without creating accounts by hand.
 */
final class SyntheticFixtureCatalog
{
    /** The password used when FIXTURE_PASSWORD is not set. Documented, synthetic, dev-only. */
    public const FALLBACK_PASSWORD = 'Keelson-Demo-2026!';

    /** Registration requires 10 characters, so a shorter fixture password would seed unusable accounts. */
    public const MINIMUM_PASSWORD_LENGTH = 10;

    /**
     * @return array{
     *     principals: list<array{key: string, displayName: string, email: string, verified: bool}>,
     *     organizations: list<array{key: string, name: string, subdomain: string}>,
     *     memberships: list<array{organization: string, principal: string, role: string}>,
     *     customers: list<array{key: string, organization: string, name: string, email: string, phone: ?string, billingAddress: ?array{street: string, city: string, postalCode: string, countryCode: string}}>,
     *     invoices: list<array{key: string, organization: string, customer: string, number: string, status: string, issueOffsetDays: int, dueOffsetDays: int, lineItems: list<array{description: string, quantity: string, unitPrice: string}>, taxRate: ?string, discountAmount: ?string}>,
     *     invitations: list<array{key: string, organization: string, email: string, role: string, invitedBy: string, expiresInDays: int, expired: bool}>,
     *     notifications: list<array{key: string, organization: string, principal: string, type: string, read: bool, daysAgo: int}>
     * }
     */
    public function definitions(): array
    {
        return [
            'principals' => [
                ['key' => 'owner', 'displayName' => 'Amina Demo Owner', 'email' => 'owner@example.test', 'verified' => true],
                ['key' => 'admin', 'displayName' => 'Youssef Demo Admin', 'email' => 'admin@example.test', 'verified' => true],
                ['key' => 'member', 'displayName' => 'Sami Demo Member', 'email' => 'member@example.test', 'verified' => true],
                ['key' => 'viewer', 'displayName' => 'Leila Demo Viewer', 'email' => 'viewer@example.test', 'verified' => true],
                // Registered but never confirmed: signing in is refused with
                // `403 email_not_verified`, which is otherwise hard to reproduce.
                ['key' => 'unverified', 'displayName' => 'Nadia Demo Unverified', 'email' => 'unverified@example.test', 'verified' => false],
            ],
            'organizations' => [
                ['key' => 'northwind', 'name' => 'Northwind Demo Co. (fixture)', 'subdomain' => 'fixture-northwind'],
                ['key' => 'contoso', 'name' => 'Contoso Demo Co. (fixture)', 'subdomain' => 'fixture-contoso'],
            ],
            'memberships' => [
                ['organization' => 'northwind', 'principal' => 'owner', 'role' => 'Owner'],
                ['organization' => 'northwind', 'principal' => 'admin', 'role' => 'Admin'],
                ['organization' => 'northwind', 'principal' => 'member', 'role' => 'Member'],
                ['organization' => 'contoso', 'principal' => 'viewer', 'role' => 'Viewer'],
                // One principal belongs to both organizations so tenant switching
                // and the per-organization role difference can both be exercised.
                ['organization' => 'contoso', 'principal' => 'member', 'role' => 'Member'],
            ],
            'customers' => [
                ['key' => 'atlas', 'organization' => 'northwind', 'name' => 'Atlas Harbour Supplies (fixture)', 'email' => 'atlas@example.test', 'phone' => '+21600000011', 'billingAddress' => ['street' => '12 Rue du Port', 'city' => 'Tunis', 'postalCode' => '1000', 'countryCode' => 'tn']],
                ['key' => 'brightline', 'organization' => 'northwind', 'name' => 'Brightline Cafe (fixture)', 'email' => 'brightline@example.test', 'phone' => '+21600000012', 'billingAddress' => ['street' => '4 Avenue Habib Bourguiba', 'city' => 'Sousse', 'postalCode' => '4000', 'countryCode' => 'tn']],
                ['key' => 'cedar', 'organization' => 'northwind', 'name' => 'Cedar and Pine Studio (fixture)', 'email' => 'cedar@example.test', 'phone' => null, 'billingAddress' => ['street' => '88 Rue des Oliviers', 'city' => 'Sfax', 'postalCode' => '3000', 'countryCode' => 'tn']],
                ['key' => 'dune', 'organization' => 'northwind', 'name' => 'Dune Textiles (fixture)', 'email' => 'dune@example.test', 'phone' => '+21600000014', 'billingAddress' => null],
                ['key' => 'evergreen', 'organization' => 'northwind', 'name' => 'Evergreen Print Shop (fixture)', 'email' => 'evergreen@example.test', 'phone' => '+21600000015', 'billingAddress' => ['street' => '21 Rue de Marseille', 'city' => 'Tunis', 'postalCode' => '1001', 'countryCode' => 'tn']],
                ['key' => 'fjord', 'organization' => 'northwind', 'name' => 'Fjord Logistics (fixture)', 'email' => 'fjord@example.test', 'phone' => null, 'billingAddress' => ['street' => '3 Quai Nord', 'city' => 'Bizerte', 'postalCode' => '7000', 'countryCode' => 'tn']],
                ['key' => 'granite', 'organization' => 'northwind', 'name' => 'Granite Works Lab (fixture)', 'email' => 'granite@example.test', 'phone' => '+21600000017', 'billingAddress' => null],
                ['key' => 'harbor', 'organization' => 'contoso', 'name' => 'Harbour Lane Books (fixture)', 'email' => 'harbor@example.test', 'phone' => '+21600000021', 'billingAddress' => ['street' => '9 Rue des Libraires', 'city' => 'Tunis', 'postalCode' => '1002', 'countryCode' => 'tn']],
                ['key' => 'iris', 'organization' => 'contoso', 'name' => 'Iris Ceramics (fixture)', 'email' => 'iris@example.test', 'phone' => null, 'billingAddress' => ['street' => '17 Rue des Potiers', 'city' => 'Nabeul', 'postalCode' => '8000', 'countryCode' => 'tn']],
                ['key' => 'juniper', 'organization' => 'contoso', 'name' => 'Juniper Bakery (fixture)', 'email' => 'juniper@example.test', 'phone' => '+21600000023', 'billingAddress' => null],
                ['key' => 'kite', 'organization' => 'contoso', 'name' => 'Kite and Anchor Design (fixture)', 'email' => 'kite@example.test', 'phone' => '+21600000024', 'billingAddress' => ['street' => '5 Rue de la Marina', 'city' => 'Monastir', 'postalCode' => '5000', 'countryCode' => 'tn']],
                ['key' => 'lumen', 'organization' => 'contoso', 'name' => 'Lumen Optics (fixture)', 'email' => 'lumen@example.test', 'phone' => null, 'billingAddress' => null],
            ],
            'invoices' => self::invoices(),
            // Two live invitations and one that lapsed, so both the pending list
            // and the expired state can be seen without waiting a week. The
            // fixture token is derived from its key (`fixture-invitation-<key>`),
            // which makes the public link usable in development.
            'invitations' => [
                ['key' => 'designer', 'organization' => 'northwind', 'email' => 'designer@example.test', 'role' => 'Member', 'invitedBy' => 'owner', 'expiresInDays' => 7, 'expired' => false],
                ['key' => 'analyst', 'organization' => 'northwind', 'email' => 'analyst@example.test', 'role' => 'Viewer', 'invitedBy' => 'admin', 'expiresInDays' => 5, 'expired' => false],
                ['key' => 'auditor', 'organization' => 'contoso', 'email' => 'auditor@example.test', 'role' => 'Viewer', 'invitedBy' => 'member', 'expiresInDays' => 3, 'expired' => true],
            ],
            // A believable inbox: both read and unread, the four notification
            // types, and recipients on both sides of the Owner/Admin split.
            'notifications' => [
                ['key' => 'owner-overdue', 'organization' => 'northwind', 'principal' => 'owner', 'type' => 'invoice_overdue', 'read' => false, 'daysAgo' => 0],
                ['key' => 'owner-paid', 'organization' => 'northwind', 'principal' => 'owner', 'type' => 'invoice_paid', 'read' => false, 'daysAgo' => 1],
                ['key' => 'owner-joined', 'organization' => 'northwind', 'principal' => 'owner', 'type' => 'member_joined', 'read' => true, 'daysAgo' => 3],
                ['key' => 'owner-invitation', 'organization' => 'northwind', 'principal' => 'owner', 'type' => 'invitation_accepted', 'read' => true, 'daysAgo' => 6],
                ['key' => 'admin-overdue', 'organization' => 'northwind', 'principal' => 'admin', 'type' => 'invoice_overdue', 'read' => false, 'daysAgo' => 0],
                ['key' => 'admin-paid', 'organization' => 'northwind', 'principal' => 'admin', 'type' => 'invoice_paid', 'read' => true, 'daysAgo' => 2],
                ['key' => 'admin-joined', 'organization' => 'northwind', 'principal' => 'admin', 'type' => 'member_joined', 'read' => false, 'daysAgo' => 4],
                ['key' => 'admin-invitation', 'organization' => 'northwind', 'principal' => 'admin', 'type' => 'invitation_accepted', 'read' => true, 'daysAgo' => 7],
            ],
        ];
    }

    /**
     * Thirty invoices, numbered the way the API numbers them: one sequence per
     * organization and year, `YYYY-NNNNNN`.
     *
     * Dates are offsets from "today" rather than fixed dates, so overdue and
     * upcoming states keep existing however long this data sits in a database.
     *
     * @return list<array{key: string, organization: string, customer: string, number: string, status: string, issueOffsetDays: int, dueOffsetDays: int, lineItems: list<array{description: string, quantity: string, unitPrice: string}>, taxRate: ?string, discountAmount: ?string}>
     */
    private static function invoices(): array
    {
        $year = (new \DateTimeImmutable('today'))->format('Y');
        $sequences = ['northwind' => 0, 'contoso' => 0];
        $rows = [
            // Northwind (18): 2 drafts, 5 sent and upcoming, 3 overdue, 8 paid.
            ['organization' => 'northwind', 'customer' => 'atlas', 'status' => 'draft', 'issueOffsetDays' => -1, 'dueOffsetDays' => 29, 'lineItems' => [['description' => 'Brand refresh discovery workshop', 'quantity' => '6.000', 'unitPrice' => '95.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'dune', 'status' => 'draft', 'issueOffsetDays' => 0, 'dueOffsetDays' => 30, 'lineItems' => [['description' => 'Warehouse signage concept', 'quantity' => '1.000', 'unitPrice' => '420.000'], ['description' => 'Site survey', 'quantity' => '2.000', 'unitPrice' => '60.000']], 'taxRate' => '13.0000', 'discountAmount' => '20.000'],
            ['organization' => 'northwind', 'customer' => 'brightline', 'status' => 'sent', 'issueOffsetDays' => -3, 'dueOffsetDays' => 12, 'lineItems' => [['description' => 'Spring menu photography', 'quantity' => '2.000', 'unitPrice' => '320.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'cedar', 'status' => 'sent', 'issueOffsetDays' => -6, 'dueOffsetDays' => 8, 'lineItems' => [['description' => 'Packaging illustration set', 'quantity' => '3.000', 'unitPrice' => '180.000'], ['description' => 'Revision round', 'quantity' => '2.000', 'unitPrice' => '90.000']], 'taxRate' => '19.0000', 'discountAmount' => '50.000'],
            ['organization' => 'northwind', 'customer' => 'evergreen', 'status' => 'sent', 'issueOffsetDays' => -9, 'dueOffsetDays' => 5, 'lineItems' => [['description' => 'Quarterly print run', 'quantity' => '1200.000', 'unitPrice' => '0.850']], 'taxRate' => null, 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'fjord', 'status' => 'sent', 'issueOffsetDays' => -12, 'dueOffsetDays' => 18, 'lineItems' => [['description' => 'Route audit report', 'quantity' => '1.000', 'unitPrice' => '1450.000']], 'taxRate' => '7.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'granite', 'status' => 'sent', 'issueOffsetDays' => -14, 'dueOffsetDays' => 2, 'lineItems' => [['description' => 'Lab equipment calibration', 'quantity' => '4.000', 'unitPrice' => '210.000']], 'taxRate' => '13.0000', 'discountAmount' => '40.000'],
            ['organization' => 'northwind', 'customer' => 'atlas', 'status' => 'overdue', 'issueOffsetDays' => -40, 'dueOffsetDays' => -10, 'lineItems' => [['description' => 'Harbour kiosk build', 'quantity' => '1.000', 'unitPrice' => '2850.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'brightline', 'status' => 'overdue', 'issueOffsetDays' => -55, 'dueOffsetDays' => -25, 'lineItems' => [['description' => 'Coffee cart wrap', 'quantity' => '2.000', 'unitPrice' => '640.000']], 'taxRate' => '13.0000', 'discountAmount' => '30.000'],
            ['organization' => 'northwind', 'customer' => 'cedar', 'status' => 'overdue', 'issueOffsetDays' => -70, 'dueOffsetDays' => -40, 'lineItems' => [['description' => 'Studio signage install', 'quantity' => '1.000', 'unitPrice' => '1780.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'dune', 'status' => 'paid', 'issueOffsetDays' => -110, 'dueOffsetDays' => -95, 'lineItems' => [['description' => 'Textile sample library', 'quantity' => '3.000', 'unitPrice' => '380.000']], 'taxRate' => '13.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'evergreen', 'status' => 'paid', 'issueOffsetDays' => -100, 'dueOffsetDays' => -80, 'lineItems' => [['description' => 'Annual catalogue print', 'quantity' => '800.000', 'unitPrice' => '1.100']], 'taxRate' => '19.0000', 'discountAmount' => '60.000'],
            ['organization' => 'northwind', 'customer' => 'fjord', 'status' => 'paid', 'issueOffsetDays' => -92, 'dueOffsetDays' => -70, 'lineItems' => [['description' => 'Cold-chain audit', 'quantity' => '1.000', 'unitPrice' => '2400.000']], 'taxRate' => '7.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'granite', 'status' => 'paid', 'issueOffsetDays' => -84, 'dueOffsetDays' => -66, 'lineItems' => [['description' => 'Prototype tooling', 'quantity' => '2.000', 'unitPrice' => '1150.000']], 'taxRate' => '19.0000', 'discountAmount' => '100.000'],
            ['organization' => 'northwind', 'customer' => 'atlas', 'status' => 'paid', 'issueOffsetDays' => -76, 'dueOffsetDays' => -55, 'lineItems' => [['description' => 'Harbour signage phase 1', 'quantity' => '1.000', 'unitPrice' => '3200.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'brightline', 'status' => 'paid', 'issueOffsetDays' => -68, 'dueOffsetDays' => -48, 'lineItems' => [['description' => 'Menu redesign', 'quantity' => '1.000', 'unitPrice' => '720.000']], 'taxRate' => '13.0000', 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'cedar', 'status' => 'paid', 'issueOffsetDays' => -60, 'dueOffsetDays' => -42, 'lineItems' => [['description' => 'Illustration retainer', 'quantity' => '1.000', 'unitPrice' => '1500.000']], 'taxRate' => null, 'discountAmount' => null],
            ['organization' => 'northwind', 'customer' => 'dune', 'status' => 'paid', 'issueOffsetDays' => -50, 'dueOffsetDays' => -35, 'lineItems' => [['description' => 'Pattern digitising', 'quantity' => '5.000', 'unitPrice' => '240.000']], 'taxRate' => '7.0000', 'discountAmount' => '25.000'],
            // Contoso (12): 2 drafts, 3 sent and upcoming, 2 overdue, 5 paid.
            ['organization' => 'contoso', 'customer' => 'harbor', 'status' => 'draft', 'issueOffsetDays' => -2, 'dueOffsetDays' => 28, 'lineItems' => [['description' => 'Reading series identity', 'quantity' => '1.000', 'unitPrice' => '1800.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'iris', 'status' => 'draft', 'issueOffsetDays' => 0, 'dueOffsetDays' => 21, 'lineItems' => [['description' => 'Kiln glaze study', 'quantity' => '3.000', 'unitPrice' => '260.000']], 'taxRate' => '13.0000', 'discountAmount' => '15.000'],
            ['organization' => 'contoso', 'customer' => 'juniper', 'status' => 'sent', 'issueOffsetDays' => -4, 'dueOffsetDays' => 10, 'lineItems' => [['description' => 'Bakery packaging', 'quantity' => '2.000', 'unitPrice' => '340.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'kite', 'status' => 'sent', 'issueOffsetDays' => -7, 'dueOffsetDays' => 6, 'lineItems' => [['description' => 'Wayfinding system', 'quantity' => '1.000', 'unitPrice' => '2650.000']], 'taxRate' => '19.0000', 'discountAmount' => '75.000'],
            ['organization' => 'contoso', 'customer' => 'lumen', 'status' => 'sent', 'issueOffsetDays' => -11, 'dueOffsetDays' => 14, 'lineItems' => [['description' => 'Optics brochure', 'quantity' => '400.000', 'unitPrice' => '1.400']], 'taxRate' => null, 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'harbor', 'status' => 'overdue', 'issueOffsetDays' => -35, 'dueOffsetDays' => -5, 'lineItems' => [['description' => 'Shop window build', 'quantity' => '1.000', 'unitPrice' => '1950.000']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'iris', 'status' => 'overdue', 'issueOffsetDays' => -48, 'dueOffsetDays' => -18, 'lineItems' => [['description' => 'Ceramics fair stand', 'quantity' => '2.000', 'unitPrice' => '780.000']], 'taxRate' => '13.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'juniper', 'status' => 'paid', 'issueOffsetDays' => -95, 'dueOffsetDays' => -78, 'lineItems' => [['description' => 'Wholesale label run', 'quantity' => '1500.000', 'unitPrice' => '0.420']], 'taxRate' => '13.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'kite', 'status' => 'paid', 'issueOffsetDays' => -88, 'dueOffsetDays' => -62, 'lineItems' => [['description' => 'Identity refresh', 'quantity' => '1.000', 'unitPrice' => '3100.000']], 'taxRate' => '19.0000', 'discountAmount' => '120.000'],
            ['organization' => 'contoso', 'customer' => 'lumen', 'status' => 'paid', 'issueOffsetDays' => -74, 'dueOffsetDays' => -52, 'lineItems' => [['description' => 'Product photography', 'quantity' => '3.000', 'unitPrice' => '420.000']], 'taxRate' => '7.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'harbor', 'status' => 'paid', 'issueOffsetDays' => -63, 'dueOffsetDays' => -45, 'lineItems' => [['description' => 'Event programme', 'quantity' => '600.000', 'unitPrice' => '1.050']], 'taxRate' => '19.0000', 'discountAmount' => null],
            ['organization' => 'contoso', 'customer' => 'iris', 'status' => 'paid', 'issueOffsetDays' => -54, 'dueOffsetDays' => -38, 'lineItems' => [['description' => 'Studio open day', 'quantity' => '1.000', 'unitPrice' => '860.000']], 'taxRate' => null, 'discountAmount' => '40.000'],
        ];

        $invoices = [];
        foreach ($rows as $index => $row) {
            ++$sequences[$row['organization']];
            $key = sprintf('%s-%02d', $row['organization'], $index + 1);
            // `+` keeps the computed number: the row itself never carries one.
            $invoices[] = ['key' => $key, 'number' => sprintf('%s-%06d', $year, $sequences[$row['organization']])] + $row;
        }

        return $invoices;
    }
}
