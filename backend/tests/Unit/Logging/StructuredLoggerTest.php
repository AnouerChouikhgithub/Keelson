<?php

declare(strict_types=1);

namespace App\Tests\Unit\Logging;

use App\Logging\RequestContext;
use App\Logging\StructuredLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class StructuredLoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'keelson-log-');
        self::assertNotFalse($file);
        $this->logFile = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->logFile)) {
            unlink($this->logFile);
        }
    }

    public function testWritesOneJsonRecordWithTheRequestId(): void
    {
        $request = Request::create('/api/customers');
        $request->attributes->set('_request_id', 'abc12345');

        $this->logger($request)->info('Request handled', ['status' => 200]);

        $records = $this->records();
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame('Request handled', $records[0]['message']);
        self::assertSame('abc12345', $records[0]['request_id']);
        self::assertSame(200, $records[0]['context']['status']);
    }

    public function testRedactsSensitiveKeysAndConnectionStrings(): void
    {
        $this->logger()->error('Authentication failed', [
            'password' => 'hunter2',
            'authorization' => 'Bearer opaque-token',
            'nested' => ['client_secret' => 'nested-secret'],
            'detail' => 'postgresql://app:app@database.internal/keelson',
        ]);

        $line = (string) file_get_contents($this->logFile);

        self::assertStringNotContainsString('hunter2', $line);
        self::assertStringNotContainsString('opaque-token', $line);
        self::assertStringNotContainsString('nested-secret', $line);
        self::assertStringNotContainsString('app:app@', $line);
        self::assertStringContainsString('[redacted]', $line);
    }

    public function testNormalisesAnExceptionWithoutDroppingItsClass(): void
    {
        $this->logger()->critical('Unhandled failure', [
            'exception' => new \RuntimeException('Connection to postgresql://user:pass@host/db refused'),
        ]);

        $records = $this->records();
        $exception = $records[0]['context']['exception'];

        self::assertSame(\RuntimeException::class, $exception['class']);
        self::assertStringNotContainsString('user:pass@', $exception['message']);
        self::assertArrayHasKey('trace', $exception);
    }

    public function testRecordsBelowTheMinimumLevelAreDropped(): void
    {
        $this->logger(minimumLevel: 'warning')->info('Chatty');

        self::assertSame('', (string) file_get_contents($this->logFile));
    }

    private function logger(?Request $request = null, string $minimumLevel = 'debug'): StructuredLogger
    {
        $stack = new RequestStack();
        if (null !== $request) {
            $stack->push($request);
        }

        return new StructuredLogger(
            $this->createMock(LoggerInterface::class),
            new RequestContext($stack),
            $this->logFile,
            $minimumLevel,
        );
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        $records = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $records[] = $decoded;
        }

        return $records;
    }
}
