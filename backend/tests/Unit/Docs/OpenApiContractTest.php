<?php

declare(strict_types=1);

namespace App\Tests\Unit\Docs;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps docs/openapi.yaml and the router describing the same surface.
 *
 * A hand-written spec rots silently: a route is added, the document is not, and
 * nobody notices until a client is written against it. This compares the two sets
 * of `(METHOD, path)` and fails on either direction of drift, so the spec cannot
 * document a route that no longer exists or omit one that does.
 */
final class OpenApiContractTest extends KernelTestCase
{
    private const SPEC = __DIR__.'/../../../../docs/openapi.yaml';
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

    public function testSpecCoversExactlyTheRoutedApiSurface(): void
    {
        $documented = $this->documentedOperations();
        $routed = $this->routedOperations();

        self::assertNotSame([], $routed, 'The router filter matched no API route; the test is wrong.');
        self::assertSame([], array_values(array_diff($routed, $documented)), 'Routes missing from docs/openapi.yaml.');
        self::assertSame([], array_values(array_diff($documented, $routed)), 'docs/openapi.yaml documents operations that have no route.');
        self::assertSame($routed, $documented, 'The documented and routed surfaces differ.');
    }

    public function testEveryOperationDocumentsItsResponses(): void
    {
        /** @var array{paths: array<string, array<string, mixed>>} $spec */
        $spec = Yaml::parseFile(self::SPEC);
        $missing = [];

        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (!is_string($method) || !in_array($method, self::METHODS, true)) {
                    continue;
                }
                if (!isset($operations[$method]['responses']) || [] === $operations[$method]['responses']) {
                    $missing[] = strtoupper($method).' '.$path;
                }
            }
        }

        self::assertSame([], $missing, 'Operations without a documented response.');
    }

    /** @return list<string> */
    private function documentedOperations(): array
    {
        /** @var array{paths: array<string, array<string, mixed>>} $spec */
        $spec = Yaml::parseFile(self::SPEC);
        $operations = [];

        foreach ($spec['paths'] as $path => $item) {
            foreach (array_keys($item) as $method) {
                if (is_string($method) && in_array($method, self::METHODS, true)) {
                    $operations[] = strtoupper($method).' '.$this->normalize($path);
                }
            }
        }

        sort($operations);

        return $operations;
    }

    /** @return list<string> */
    private function routedOperations(): array
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertNotNull($router);

        $operations = [];
        foreach ($router->getRouteCollection() as $route) {
            /** @var Route $route */
            $path = $route->getPath();
            if (!str_starts_with($path, '/api') && !in_array($path, ['/healthz', '/readyz'], true)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $operations[] = strtoupper($method).' '.$this->normalize($path);
            }
        }

        sort($operations);

        return $operations;
    }

    /** Path placeholders are compared by position, not by name. */
    private function normalize(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
