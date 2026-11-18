<?php

declare(strict_types=1);

namespace App\Tests\Ai\Eval;

use App\Ai\AiFeatureFlags;
use App\Ai\AiGateway;
use App\Ai\FakeAiProvider;
use App\Ai\Safety\OutputSanitizer;
use App\Ai\Safety\PromptBuilder;
use App\Entity\Membership;
use App\Entity\Organization;
use App\Entity\OrganizationAiSettings;
use App\Entity\Principal;
use App\Guide\GuideIndex;
use App\Guide\GuideMessages;
use App\Guide\GuidePrompt;
use App\Guide\GuideService;
use App\Repository\MembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The retrieval evaluation: 40 documented questions per language, plus 10 off-topic ones.
 *
 * The measurement is deliberately about **retrieval**, not the model's prose: an answer
 * is only as good as the articles it was built from, the expected article id is a fact
 * about the corpus rather than a judgement about style, and the same expectation holds
 * whatever provider is configured. That is what lets one data set run in the normal CI
 * job with FakeAiProvider and in the `workflow_dispatch` job against a real provider,
 * and the pass rate stays comparable between them.
 *
 * A question passes when the expected article is the **first** source. A hit that only
 * reaches the top three is still reported as a miss: three articles is the retrieval
 * budget, so "somewhere in the context" is exactly the weak signal a pass rate should
 * not hide.
 *
 * The off-topic set proves the other half of the contract: the fixed not-covered message,
 * no sources, and an empty ledger — no provider call, no spend.
 *
 * The class is in the `ai-eval` group, which is how the real-provider workflow selects
 * it and how an operator runs it alone.
 */
#[Group('ai-eval')]
final class GuideEvalTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private GuideService $guide;
    private AiFeatureFlags $flags;
    private FakeAiProvider $fake;
    private Organization $organization;
    private Principal $principal;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();

        $flags = $container->get(AiFeatureFlags::class);
        self::assertInstanceOf(AiFeatureFlags::class, $flags);
        $this->flags = $flags;
        $this->flags->overrideGlobalEnabledForTests(true);

        $fake = $container->get(FakeAiProvider::class);
        self::assertInstanceOf(FakeAiProvider::class, $fake);
        $this->fake = $fake;
        $this->fake->failWith(FakeAiProvider::FAILURE_NONE);

        $gateway = $container->get(AiGateway::class);
        self::assertInstanceOf(AiGateway::class, $gateway);

        $memberships = $this->entityManager->getRepository(Membership::class);
        self::assertInstanceOf(MembershipRepository::class, $memberships);

        $this->guide = new GuideService(
            new GuideIndex($container->getParameter('kernel.project_dir').'/resources/guide'),
            new GuidePrompt(new PromptBuilder()),
            $gateway,
            new OutputSanitizer(),
            $memberships,
        );

        $suffix = bin2hex(random_bytes(6));
        $this->organization = new Organization('Guide eval '.$suffix, 'guide-eval-'.$suffix);
        $this->principal = new Principal('guide-eval-'.$suffix.'@example.test', 'Guide Eval');
        $this->entityManager->persist($this->organization);
        $this->entityManager->persist($this->principal);
        $this->entityManager->flush();
        $this->entityManager->persist(new Membership($this->organization, $this->principal, 'Owner'));
        $this->entityManager->persist(new OrganizationAiSettings($this->organization, true, 10000000));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        $this->flags?->overrideGlobalEnabledForTests(true);
        $this->fake?->failWith(FakeAiProvider::FAILURE_NONE);

        if (isset($this->entityManager) && $this->entityManager->isOpen()) {
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement('DELETE FROM ai_requests WHERE organization_id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM organizations WHERE id = ?', [$this->organization->getId()]);
            $connection->executeStatement('DELETE FROM principals WHERE id = ?', [$this->principal->getId()]);
            $this->entityManager->close();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string, string}> */
    public static function englishQuestions(): iterable
    {
        yield from self::questionsFor('en');
    }

    /** @return iterable<string, array{string, string}> */
    public static function frenchQuestions(): iterable
    {
        yield from self::questionsFor('fr');
    }

    /** @return iterable<string, array{string, string}> */
    public static function arabicQuestions(): iterable
    {
        yield from self::questionsFor('ar');
    }

    #[DataProvider('englishQuestions')]
    public function testEnglishQuestionsRetrieveTheExpectedArticleFirst(string $article, string $question): void
    {
        $this->assertExpectedArticle('en', $article, $question);
    }

    #[DataProvider('frenchQuestions')]
    public function testFrenchQuestionsRetrieveTheExpectedArticleFirst(string $article, string $question): void
    {
        $this->assertExpectedArticle('fr', $article, $question);
    }

    #[DataProvider('arabicQuestions')]
    public function testArabicQuestionsRetrieveTheExpectedArticleFirst(string $article, string $question): void
    {
        $this->assertExpectedArticle('ar', $article, $question);
    }

    /** The not-covered message is part of the API, so it is asserted exactly, per locale. */
    public function testOffTopicQuestionsAreAnsweredLocallyInEveryLanguage(): void
    {
        foreach (EvalCorpus::offTopic() as $case) {
            $answer = $this->ask($case['locale'], $case['question']);

            self::assertSame(GuideMessages::notCovered($case['locale']), $answer['answer'], $case['id'].' must use the fixed not-covered message.');
            self::assertSame([], $answer['sources'], $case['id'].' must not cite a source.');
            self::assertSame([], $this->ledger(), $case['id'].' must not reach a provider.');
        }
    }

    /** The whole off-topic set, one provider call between them: still nothing. */
    public function testTheOffTopicSetNeverSpendsAToken(): void
    {
        foreach (EvalCorpus::offTopic('en') as $case) {
            $this->ask('en', $case['question']);
        }

        self::assertSame([], $this->ledger());
        self::assertSame(0, $this->usedTokens(), 'An uncovered question must not reserve or settle budget.');
        self::assertSame(0, $this->reservedTokens());
    }

    private function assertExpectedArticle(string $locale, string $article, string $question): void
    {
        $answer = $this->ask($locale, $question);

        self::assertNotSame([], $answer['sources'], 'A covered question must retrieve at least one article.');
        self::assertSame($article, $answer['sources'][0]['id'], 'The expected article must rank first.');
        self::assertNotSame(GuideMessages::notCovered($locale), $answer['answer']);

        // What the client renders: plain text, with no markup and no link to follow.
        self::assertStringNotContainsString('<', $answer['answer']);
        self::assertStringNotContainsString('](', $answer['answer']);
        self::assertStringNotContainsString('http', $answer['answer']);

        $ledger = $this->ledger();
        self::assertCount(1, $ledger, 'A covered question spends exactly one provider call.');
        self::assertSame('succeeded', $ledger[0]['status']);
    }

    /** @return array{answer: string, sources: list<array{id: string, title: string}>} */
    private function ask(string $locale, string $question): array
    {
        return $this->guide->answer($this->principal, $this->organization->getId(), $question, $locale);
    }

    /** @return iterable<string, array{string, string}> */
    private static function questionsFor(string $locale): iterable
    {
        foreach (EvalCorpus::guide($locale) as $case) {
            yield $case['id'] => [$case['article'], $case['question']];
        }
    }

    /** @return list<array<string, mixed>> */
    private function ledger(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT status, feature, total_tokens FROM ai_requests WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function usedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT used_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }

    private function reservedTokens(): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne(
            'SELECT reserved_tokens FROM organization_ai_settings WHERE organization_id = ?',
            [$this->organization->getId()],
        );
    }
}
