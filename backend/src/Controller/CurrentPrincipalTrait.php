<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Principal;
use App\Exception\ApiException;

/**
 * The signed-in principal, or the API's standard 401.
 *
 * Every controller runs behind the firewall, so a missing principal means the
 * request slipped past it; answering the documented `unauthenticated` error is
 * still better than a TypeError.
 */
trait CurrentPrincipalTrait
{
    private function currentPrincipal(): Principal
    {
        $principal = $this->getUser();
        if (!$principal instanceof Principal) {
            throw ApiException::unauthenticated();
        }

        return $principal;
    }
}
