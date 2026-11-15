<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class JsonRequestEnvelopeTest extends TestCase
{
    private const string TOO_LARGE = 'api.problem.test_payload_too_large';

    private JsonRequestEnvelope $envelope;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $this->envelope = new JsonRequestEnvelope($translator);
    }

    public function testRefusesAnotherMediaType(): void
    {
        $result = $this->envelope->body($this->request('{}', 'text/plain'), BodyLimit::Tiny, self::TOO_LARGE);

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(415, $result->getStatusCode());
    }

    public function testRefusesABodyOneByteOverTheLimit(): void
    {
        $result = $this->envelope->body($this->request($this->padded(1_025)), BodyLimit::Tiny, self::TOO_LARGE);

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(413, $result->getStatusCode());
        self::assertStringContainsString(self::TOO_LARGE.'.detail', (string) $result->getContent());
    }

    public function testAcceptsABodyExactlyAtTheLimit(): void
    {
        $result = $this->envelope->body($this->request($this->padded(1_024)), BodyLimit::Tiny, self::TOO_LARGE);

        self::assertIsArray($result);
    }

    public function testRefusesInvalidJson(): void
    {
        $result = $this->envelope->body($this->request('{"a":'), BodyLimit::Tiny, self::TOO_LARGE);

        self::assertInstanceOf(JsonResponse::class, $result);
        self::assertSame(400, $result->getStatusCode());
    }

    public function testReturnsTheDecodedBody(): void
    {
        $result = $this->envelope->body($this->request('{"name":"x"}'), BodyLimit::Tiny, self::TOO_LARGE);

        self::assertSame(['name' => 'x'], $result);
    }

    public function testJsonAnswersAreNotStored(): void
    {
        $response = $this->envelope->json(['a' => 1], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[DataProvider('identifiers')]
    public function testIdentifierShape(string $value, bool $valid): void
    {
        self::assertSame($valid, Identifier::isValid($value));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function identifiers(): iterable
    {
        yield 'uuid v4' => ['0198a5e0-7c1b-4a3e-8f21-123456789abc', true];
        yield 'uppercase' => ['0198A5E0-7C1B-4A3E-8F21-123456789ABC', false];
        yield 'trailing newline' => ["0198a5e0-7c1b-4a3e-8f21-123456789abc\n", false];
        yield 'wrong variant' => ['0198a5e0-7c1b-4a3e-cf21-123456789abc', false];
        yield 'empty' => ['', false];
    }

    private function request(string $content, string $contentType = 'application/json'): Request
    {
        return Request::create('/', 'POST', server: ['CONTENT_TYPE' => $contentType], content: $content);
    }

    /** A valid JSON object of exactly $bytes bytes. */
    private function padded(int $bytes): string
    {
        $prefix = '{"p":"';
        $suffix = '"}';

        return $prefix.str_repeat('a', $bytes - strlen($prefix) - strlen($suffix)).$suffix;
    }
}
