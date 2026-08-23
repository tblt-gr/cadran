<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FoundationStatusControllerTest extends WebTestCase
{
    public function testItExposesTheFoundationStatusWithoutCaching(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/status');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertResponseHeaderSame('cache-control', 'no-store, private');
        self::assertJsonStringEqualsJsonString(
            '{"status":"ready","apiVersion":"v1"}',
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testItUsesProblemDetailsForUnknownApiRoutes(): void
    {
        $client = self::createClient();

        $client->request('GET', '/api/v1/unknown');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('content-type', 'application/problem+json');
        self::assertJsonStringEqualsJsonString(
            '{"type":"about:blank","title":"Not Found","status":404,"detail":"The requested API resource was not found."}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
