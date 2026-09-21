<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\OpenApiAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    use OpenApiAssertions;

    public function test_it_reports_that_the_application_is_up(): void
    {
        $client = static::createClient();
        $client->request('GET', '/health');

        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString(
            '{"status":"ok"}',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertResponseIsDocumented($client);
    }
}
