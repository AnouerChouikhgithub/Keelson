<?php

declare(strict_types=1);

namespace App\Input;

use App\Entity\Organization;
use Symfony\Component\Validator\Constraints as Assert;

/** Body of `POST /api/organizations`. */
final class CreateOrganizationInput
{
    /**
     * Names an organization may not take.
     *
     * These are hostnames the product itself uses (or will use): letting a tenant
     * own `api` or `admin` would put their content on an address customers and
     * tooling already trust.
     */
    public const RESERVED_SUBDOMAINS = [
        'www', 'api', 'admin', 'app', 'auth', 'mail', 'smtp', 'imap', 'ftp',
        'static', 'cdn', 'assets', 'dashboard', 'docs', 'status', 'support',
        'help', 'billing', 'payments', 'internal', 'system', 'root', 'security',
        'localhost', 'test', 'dev', 'staging', 'keelson',
    ];

    #[Assert\NotBlank(message: 'An organization name is required.')]
    #[Assert\Length(min: 2, max: 120, minMessage: 'The organization name must contain at least {{ limit }} characters.', maxMessage: 'The organization name must contain at most {{ limit }} characters.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'A subdomain is required.')]
    #[Assert\Length(min: 3, max: 63, minMessage: 'The subdomain must contain at least {{ limit }} characters.', maxMessage: 'The subdomain must contain at most {{ limit }} characters.')]
    #[Assert\Regex(pattern: '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', message: 'The subdomain may contain lowercase letters, digits and hyphens, and must start and end with a letter or digit.')]
    public string $subdomain = '';
}
