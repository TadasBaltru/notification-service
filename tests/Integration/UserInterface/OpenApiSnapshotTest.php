<?php

declare(strict_types=1);

namespace App\Tests\Integration\UserInterface;

use App\Tests\Support\OpenApiAssertions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OpenApiSnapshotTest extends KernelTestCase
{
    use OpenApiAssertions;

    public function test_committed_openapi_json_matches_the_generated_spec(): void
    {
        $snapshotFile = \dirname(__DIR__, 3) . '/docs/openapi.json';
        self::assertFileExists(
            $snapshotFile,
            'docs/openapi.json is missing. Run: docker compose exec -T app bin/console nelmio:apidoc:dump --format=json > docs/openapi.json',
        );

        $raw = file_get_contents($snapshotFile);
        self::assertNotFalse($raw);
        $decoded = json_decode(str_replace("\r\n", "\n", $raw), true);
        self::assertIsArray($decoded, 'docs/openapi.json is not valid JSON.');

        $expected = self::encodeNormalizedOpenApi($decoded);
        $actual = self::encodeNormalizedOpenApi(self::openApiSpec());

        self::assertSame(
            $expected,
            $actual,
            'docs/openapi.json is stale. Run: docker compose exec -T app bin/console nelmio:apidoc:dump --format=json > docs/openapi.json',
        );
    }
}
