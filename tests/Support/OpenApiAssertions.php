<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * @phpstan-require-extends KernelTestCase
 */
trait OpenApiAssertions
{
    /** @var array<string, mixed>|null */
    private static ?array $openApiSpecCache = null;

    public function assertResponseIsDocumented(KernelBrowser $client): void
    {
        $request = $client->getRequest();
        $response = $client->getResponse();
        $router = self::getContainer()->get('router');
        Assert::assertInstanceOf(RouterInterface::class, $router);

        $method = strtolower($request->getMethod());
        $status = (string) $response->getStatusCode();
        $match = $router->match($request->getPathInfo());
        $routeName = $match['_route'] ?? null;
        Assert::assertIsString($routeName);
        $route = $router->getRouteCollection()->get($routeName);
        Assert::assertNotNull($route, \sprintf('Unknown route "%s".', $routeName));
        $pattern = $route->getPath();

        $spec = self::openApiSpec();
        $paths = $spec['paths'] ?? null;
        Assert::assertIsArray($paths);
        Assert::assertArrayHasKey(
            $pattern,
            $paths,
            \sprintf('OpenAPI spec has no path "%s".', $pattern),
        );
        $operations = $paths[$pattern];
        Assert::assertIsArray($operations);
        Assert::assertArrayHasKey(
            $method,
            $operations,
            \sprintf('OpenAPI spec has no %s operation on "%s".', strtoupper($method), $pattern),
        );
        $operation = $operations[$method];
        Assert::assertIsArray($operation);
        $responses = $operation['responses'] ?? null;
        Assert::assertIsArray($responses);
        Assert::assertArrayHasKey(
            $status,
            $responses,
            \sprintf('Status %s for %s %s is not documented in OpenAPI.', $status, strtoupper($method), $pattern),
        );

        $contentType = (string) $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'json')) {
            return;
        }

        $body = json_decode((string) $response->getContent(), true);
        Assert::assertIsArray($body, 'JSON response body must decode to an object or array.');

        $documented = $responses[$status];
        Assert::assertIsArray($documented);
        $schema = self::jsonSchemaForResponse($documented, $spec);
        Assert::assertNotNull(
            $schema,
            \sprintf('Documented status %s for %s %s has no application/json schema.', $status, strtoupper($method), $pattern),
        );

        $properties = $schema['properties'] ?? [];
        Assert::assertIsArray($properties);
        /** @var list<int|string> $declaredKeys */
        $declaredKeys = array_keys($properties);
        $required = $schema['required'] ?? [];
        Assert::assertIsArray($required);

        $bodyKeys = array_keys($body);
        foreach ($required as $key) {
            Assert::assertIsString($key);
            Assert::assertContains(
                $key,
                $bodyKeys,
                \sprintf('Response is missing required documented field "%s".', $key),
            );
        }
        foreach ($bodyKeys as $key) {
            Assert::assertContains(
                $key,
                $declaredKeys,
                \sprintf('Response field "%s" is not declared in the OpenAPI schema for %s %s (%s).', $key, strtoupper($method), $pattern, $status),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function openApiSpec(): array
    {
        if (null === self::$openApiSpecCache) {
            self::$openApiSpecCache = self::loadOpenApiSpec(self::getContainer());
        }

        return self::$openApiSpecCache;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function loadOpenApiSpec(ContainerInterface $container): array
    {
        // Public alias of nelmio_api_doc.generator.default. Do not use
        // generator_locator->get('default'): phpstan-symfony treats that as a missing container id.
        $generator = $container->get('nelmio_api_doc.generator');
        Assert::assertIsObject($generator);
        Assert::assertTrue(method_exists($generator, 'generate'));
        $openApi = $generator->generate();
        Assert::assertIsObject($openApi);
        Assert::assertTrue(method_exists($openApi, 'toJson'), 'OpenApi document must expose toJson() (swagger-php 6).');
        $spec = json_decode($openApi->toJson(), true);
        Assert::assertIsArray($spec);

        /** @var array<string, mixed> $spec */
        return $spec;
    }

    /**
     * @param array<string, mixed> $spec
     */
    protected static function encodeNormalizedOpenApi(array $spec): string
    {
        self::ksortRecursive($spec);
        $json = json_encode($spec, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        return str_replace("\r\n", "\n", $json) . "\n";
    }

    /**
     * @param array<string, mixed> $responseDoc
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>|null
     */
    private static function jsonSchemaForResponse(array $responseDoc, array $spec): ?array
    {
        $content = $responseDoc['content'] ?? null;
        if (!\is_array($content)) {
            return null;
        }

        $media = $content['application/json'] ?? $content['application/problem+json'] ?? null;
        if (!\is_array($media)) {
            $first = reset($content);
            $media = \is_array($first) ? $first : null;
        }
        if (!\is_array($media)) {
            return null;
        }

        $schema = $media['schema'] ?? null;
        if (!\is_array($schema)) {
            return null;
        }

        $ref = $schema['$ref'] ?? null;
        if (\is_string($ref)) {
            $resolved = self::resolveOpenApiRef($ref, $spec);

            return $resolved ?? $schema;
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return array<string, mixed>|null
     */
    private static function resolveOpenApiRef(string $ref, array $spec): ?array
    {
        if (!str_starts_with($ref, '#/')) {
            return null;
        }

        $node = $spec;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $part = str_replace('~1', '/', str_replace('~0', '~', $part));
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return \is_array($node) ? $node : null;
    }

    /**
     * @param array<mixed> $value
     */
    private static function ksortRecursive(array &$value): void
    {
        foreach ($value as &$item) {
            if (\is_array($item)) {
                self::ksortRecursive($item);
            }
        }
        unset($item);
        ksort($value);
    }
}
