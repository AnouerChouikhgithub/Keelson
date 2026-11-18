<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Routed stub for the product assistant.
 *
 * The contract is fixed in `docs/api/ai.md` so another track can build against it; this
 * endpoint deliberately answers `501` until that generator exists. It still sits behind
 * the firewall, so the stub does not disclose itself to anonymous callers.
 */
final class AiGuideController extends AbstractController
{
    #[Route('/api/ai/guide', name: 'app_api_ai_guide', methods: ['POST'])]
    public function guide(): never
    {
        throw new ApiException(Response::HTTP_NOT_IMPLEMENTED, 'not_implemented', 'The AI guide is not implemented yet.');
    }
}
