<?php

declare(strict_types=1);

namespace App\Tests\Integration\UserInterface;

use App\Tests\Support\OpenApiAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

final class OpenApiCoverageTest extends KernelTestCase
{
    use OpenApiAssertions;

    /** @var list<string> */
    private const AREA_PATTERNS = ['#^/health#', '#^/notifications#', '#^/users#'];

    public function test_every_public_route_has_a_documented_operation(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $spec = self::openApiSpec();
        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);

        foreach ($router->getRouteCollection() as $name => $route) {
            if (str_starts_with($name, '_') || str_starts_with($name, 'app.swagger')) {
                continue;
            }

            $pattern = $route->getPath();
            if (str_starts_with($pattern, '/api/doc')) {
                continue;
            }
            if (!self::matchesArea($pattern)) {
                continue;
            }

            $methods = $route->getMethods();
            if ([] === $methods) {
                $methods = ['GET'];
            }

            foreach ($methods as $method) {
                $method = strtolower($method);
                if (\in_array($method, ['head', 'options'], true)) {
                    continue;
                }

                self::assertArrayHasKey(
                    $pattern,
                    $paths,
                    \sprintf('Route %s (%s) is not in the OpenAPI spec.', $name, $pattern),
                );
                $operations = $paths[$pattern];
                self::assertIsArray($operations);
                self::assertArrayHasKey(
                    $method,
                    $operations,
                    \sprintf('Route %s is missing a %s operation in OpenAPI.', $name, strtoupper($method)),
                );
                $operation = $operations[$method];
                self::assertIsArray($operation);
                $responses = $operation['responses'] ?? null;
                self::assertIsArray($responses, \sprintf('Route %s %s has no responses.', strtoupper($method), $pattern));

                $has2xxWithSchema = false;
                foreach ($responses as $status => $documented) {
                    $status = (string) $status;
                    self::assertIsArray($documented);
                    $first = $status[0] ?? '';
                    if ('2' === $first) {
                        $content = $documented['content'] ?? null;
                        $has2xxWithSchema = \is_array($content) && [] !== $content;
                    }
                    if ('4' === $first || '5' === $first) {
                        $ref = self::problemRef($documented);
                        self::assertSame(
                            '#/components/schemas/Problem',
                            $ref,
                            \sprintf('Status %s for %s %s must reference #/components/schemas/Problem.', $status, strtoupper($method), $pattern),
                        );
                    }
                }

                self::assertTrue(
                    $has2xxWithSchema,
                    \sprintf('Route %s %s has no 2xx response with a schema.', strtoupper($method), $pattern),
                );
            }
        }
    }

    private static function matchesArea(string $path): bool
    {
        foreach (self::AREA_PATTERNS as $regex) {
            if (1 === preg_match($regex, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $documented
     */
    private static function problemRef(array $documented): ?string
    {
        $content = $documented['content'] ?? null;
        if (!\is_array($content)) {
            return null;
        }

        foreach (['application/json', 'application/problem+json'] as $type) {
            $ref = $content[$type]['schema']['$ref'] ?? null;
            if (\is_string($ref)) {
                return $ref;
            }
        }

        return null;
    }
}
