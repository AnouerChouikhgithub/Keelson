<?php

declare(strict_types=1);

namespace App\Guide;

use App\Ai\AiGateway;
use App\Ai\AiRequest;
use App\Ai\Safety\OutputSanitizer;
use App\Entity\Membership;
use App\Entity\Principal;
use App\Exception\ApiException;
use App\Repository\MembershipRepository;

/**
 * The product assistant: retrieval first, a model only when retrieval found something.
 *
 * The caller's role is resolved from the membership, not from the request, and the
 * index filters by it before scoring, so a Viewer cannot retrieve an Owner-only
 * article. Everything below the retrieval step is the AI foundation: the gateway owns
 * the switch, the opt-in, both rate-limit buckets and the budget, and it returns text
 * that has already been stripped of markup. The guide sanitises once more before
 * returning, because its output is rendered as text in a chat bubble.
 *
 * No tenant row is read: the prompt contains articles, the role and the question.
 * That is also why an empty retrieval never reaches a provider — there is nothing to
 * ground an answer on, and a model asked to improvise about someone's workspace is
 * exactly the failure this endpoint is meant to avoid.
 */
final class GuideService
{
    public const FEATURE = 'guide';
    private const MAX_TOKENS = 700;

    public function __construct(
        private readonly GuideIndex $index,
        private readonly GuidePrompt $prompts,
        private readonly AiGateway $gateway,
        private readonly OutputSanitizer $sanitizer,
        private readonly MembershipRepository $memberships,
    ) {
    }

    /**
     * @return array{answer: string, sources: list<array{id: string, title: string}>}
     */
    public function answer(Principal $principal, string $organizationId, string $question, string $locale): array
    {
        $membership = $this->memberships->findForOrganizationAndPrincipal($organizationId, $principal->getId());
        if (!$membership instanceof Membership) {
            throw ApiException::notFound();
        }

        $matches = $this->index->search($question, $locale, $membership->getRole());
        if ([] === $matches) {
            return ['answer' => GuideMessages::notCovered($locale), 'sources' => []];
        }

        $articles = array_map(static fn (array $match): GuideArticle => $match['article'], $matches);
        $prompt = $this->prompts->build($question, $membership->getRole(), $locale, $articles);
        $response = $this->gateway->complete($principal, $organizationId, new AiRequest(
            self::FEATURE,
            $prompt['system'],
            $prompt['prompt'],
            self::MAX_TOKENS,
            $organizationId,
            $principal->getId(),
        ));

        $sources = array_map(static fn (GuideArticle $article): array => [
            'id' => $article->id,
            'title' => $article->title,
        ], $articles);

        return [
            'answer' => $this->sanitizer->sanitize($response->text),
            'sources' => $sources,
        ];
    }
}
