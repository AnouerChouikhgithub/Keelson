<?php

declare(strict_types=1);

namespace App\Tests\Unit\Guide;

use App\Guide\GuideArticle;
use App\Guide\GuideIndex;
use PHPUnit\Framework\TestCase;

/**
 * Retrieval is the security boundary of the assistant: what it cannot find, it cannot
 * send to a model. These tests pin the behaviour a question depends on — folding,
 * stop words, ranking, the score floor, role filtering and the locale fallback — and
 * the failure mode of a broken knowledge base.
 */
final class GuideIndexTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->remove($directory);
        }
        $this->temporaryDirectories = [];

        parent::tearDown();
    }

    public function testAnEnglishQuestionFindsTheInvoiceArticle(): void
    {
        $results = $this->index()->search('How do I mark an invoice paid?', 'en', 'Member');

        self::assertNotSame([], $results);
        self::assertSame('invoice-lifecycle', $results[0]['article']->id);
        self::assertGreaterThan(0.0, $results[0]['score']);
    }

    public function testFrenchAccentsAreFolded(): void
    {
        $index = $this->index();

        self::assertSame(['parametrer', 'facture'], $index->tokenize('Paramétrer la facture', 'fr'));
        self::assertSame('invoice-lifecycle', $index->search('Comment envoyer une facture ?', 'fr', 'Owner')[0]['article']->id);
    }

    public function testAnArabicQuestionFindsTheInvoiceArticle(): void
    {
        $results = $this->index()->search('الفاتورة مسودة', 'ar', 'Member');

        self::assertNotSame([], $results);
        self::assertSame('invoice-lifecycle', $results[0]['article']->id);
    }

    public function testAtMostThreeArticlesAreReturnedWithScores(): void
    {
        $results = $this->index()->search('invoice customer member notification audit settings', 'en', 'Owner');

        self::assertLessThanOrEqual(3, count($results));
        self::assertNotSame([], $results);
        foreach ($results as $result) {
            self::assertArrayHasKey('score', $result);
            self::assertInstanceOf(GuideArticle::class, $result['article']);
        }
        $scores = array_column($results, 'score');
        for ($index = 1, $count = count($scores); $index < $count; ++$index) {
            self::assertGreaterThanOrEqual($scores[$index], $scores[$index - 1], 'Results come back best first.');
        }
    }

    public function testAQuestionOutsideTheCorpusReturnsNothing(): void
    {
        self::assertSame([], $this->index()->search('What is the capital of France?', 'en', 'Owner'));
        self::assertSame([], $this->index()->search('how do i', 'en', 'Owner'), 'Stop words alone must not match.');
    }

    public function testAnOwnerOnlyArticleIsNeverRetrievedForAViewer(): void
    {
        $index = $this->index();
        $question = 'How do I enable AI and change the monthly token budget?';
        $ids = static fn (array $results): array => array_map(static fn (array $result): string => $result['article']->id, $results);

        $owner = $ids($index->search($question, 'en', 'Owner'));
        $viewer = $ids($index->search($question, 'en', 'Viewer'));

        self::assertContains('ai-settings', $owner);
        self::assertNotContains('ai-settings', $viewer, 'A Viewer must not see an Owner/Admin article.');
    }

    public function testAMissingLocaleFallsBackToEnglish(): void
    {
        $directory = $this->temporaryGuide();
        self::assertTrue(mkdir($directory.'/en', 0o777, true));
        file_put_contents($directory.'/en/greeting.md', "---\nid: greeting\ntitle: Greeting\nroles: []\n---\n\nInvoices are numbered per organization.\n");

        $results = (new GuideIndex($directory))->search('invoice numbered organization', 'fr', 'Member');

        self::assertNotSame([], $results);
        self::assertSame('greeting', $results[0]['article']->id);
    }

    public function testABrokenKnowledgeBaseFailsLoudly(): void
    {
        $directory = $this->temporaryGuide();
        mkdir($directory.'/en', 0o777, true);
        file_put_contents($directory.'/en/broken.md', "no front matter here\n");

        $this->expectException(\RuntimeException::class);
        new GuideIndex($directory);
    }

    private function index(): GuideIndex
    {
        return new GuideIndex(\dirname(__DIR__, 3).'/resources/guide');
    }

    private function temporaryGuide(): string
    {
        $directory = sys_get_temp_dir().'/keelson-guide-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0o777, true));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            self::assertInstanceOf(\SplFileInfo::class, $entry);
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
