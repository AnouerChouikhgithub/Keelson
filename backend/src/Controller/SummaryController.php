<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ApiException;
use App\Security\OrganizationVoter;
use App\Summary\SummaryKind;
use App\Summary\SummaryLocale;
use App\Summary\SummaryService;
use App\Util\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-demand summary of one organization's own data, for one signed-in member.
 *
 * The organization is a selector, never authority: the voter resolves it through
 * the caller's active membership, and the facts builder repeats that join in
 * every query. An unknown kind, a foreign tenant and an organization the caller
 * does not belong to all answer `404` with the same body, so the route cannot be
 * used to probe which tenants exist.
 *
 * `kind` is checked before anything else because it is a closed set that needs no
 * data, and the locale is the caller's preference rather than a fact about the
 * tenant. Neither is trusted for identity.
 */
final class SummaryController extends AbstractController
{
    use CurrentPrincipalTrait;

    public function __construct(private readonly SummaryService $summaries)
    {
    }

    #[Route(
        '/api/organizations/{organizationId}/ai/summaries/{kind}',
        name: 'app_api_organization_ai_summary',
        methods: ['POST'],
    )]
    public function summarize(string $organizationId, string $kind, Request $request): JsonResponse
    {
        $summaryKind = SummaryKind::tryFrom($kind);
        if (null === $summaryKind) {
            throw ApiException::notFound();
        }
        if (!Uuid::isValid($organizationId)) {
            throw ApiException::invalidId();
        }

        $principal = $this->currentPrincipal();
        if (!$this->isGranted(OrganizationVoter::READ, $organizationId)) {
            throw ApiException::notFound();
        }

        $payload = $this->summaries->summarize(
            $principal,
            $organizationId,
            $summaryKind,
            SummaryLocale::fromRequest($request),
        );

        return $this->json($payload, Response::HTTP_OK, ['Cache-Control' => 'no-store, private']);
    }
}
