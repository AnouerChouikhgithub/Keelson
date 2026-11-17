<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * The provider boundary.
 *
 * Implementations translate {@see AiRequest} into one vendor call and normalise the
 * answer into {@see AiResponse}. They must not enforce product policy: the global
 * switch, the organization opt-in, rate limits and the budget all live in
 * {@see AiGateway}, so swapping a provider cannot bypass them.
 */
interface AiProvider
{
    /**
     * @throws Exception\AiUnavailableException when the call timed out,
     *                                          failed or answered with an
     *                                          unparseable body
     */
    public function complete(AiRequest $request): AiResponse;
}
