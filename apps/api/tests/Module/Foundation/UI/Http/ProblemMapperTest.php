<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\CarriesProblem;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\ProblemRule;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProblemMapperTest extends TestCase
{
    private ProblemMapper $mapper;

    protected function setUp(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $this->mapper = new ProblemMapper(new JsonRequestEnvelope($translator));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $response = $this->mapper->respond(new SpecificTestFailure(), [
            SpecificTestFailure::class => new ProblemRule(404, 'api.problem.specific'),
            \RuntimeException::class => new ProblemRule(409, 'api.problem.general'),
        ]);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('api.problem.specific.title', (string) $response->getContent());
    }

    public function testBuildsTheDocumentFromTheRule(): void
    {
        $response = $this->mapper->respond(new \RuntimeException(), [
            \RuntimeException::class => new ProblemRule(409, 'api.problem.clash', '/problems/clash', ['code' => 'clash'], ['Retry-After' => '1']),
        ]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));
        self::assertSame(
            ['type' => '/problems/clash', 'title' => 'api.problem.clash.title', 'status' => 409, 'detail' => 'api.problem.clash.detail', 'code' => 'clash'],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testStatusOnlyRuleReadsTheKeyAndExtensionsFromTheException(): void
    {
        $response = $this->mapper->respond(new CarryingTestFailure(), [CarryingTestFailure::class => 422]);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        self::assertSame('api.problem.too_big.title', $body['title']);
        self::assertSame(12, $body['limit']);
    }

    public function testClosureRuleBuildsTheRuleFromTheException(): void
    {
        $response = $this->mapper->respond(new SpecificTestFailure(), [
            SpecificTestFailure::class => static fn (SpecificTestFailure $e): ProblemRule => new ProblemRule(418, 'api.problem.teapot'),
        ]);

        self::assertSame(418, $response->getStatusCode());
    }

    public function testRethrowsAnUnmappedExceptionUnchanged(): void
    {
        $exception = new \DomainException('unmapped');

        try {
            $this->mapper->respond($exception, [SpecificTestFailure::class => new ProblemRule(404, 'api.problem.specific')]);
            self::fail('The unmapped exception must be rethrown.');
        } catch (\DomainException $thrown) {
            self::assertSame($exception, $thrown);
        }
    }

    public function testStatusOnlyRuleOnAnExceptionWithoutProblemCodeIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);

        $this->mapper->respond(new SpecificTestFailure(), [SpecificTestFailure::class => 400]);
    }
}

final class SpecificTestFailure extends \RuntimeException
{
}

final class CarryingTestFailure extends \RuntimeException implements CarriesProblem
{
    public function problemCode(): string
    {
        return 'too_big';
    }

    public function problemExtensions(): array
    {
        return ['limit' => 12];
    }
}
