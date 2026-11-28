<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\Application\InvalidAmountInput;
use App\Module\Foundation\UI\Http\ApiProblemResponseListener;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiProblemResponseListenerTest extends KernelTestCase
{
    public function testAnInvalidAmountBecomesASafeMemberSpecificProblem(): void
    {
        $kernel = self::bootKernel();
        $listener = self::getContainer()->get(ApiProblemResponseListener::class);
        self::assertInstanceOf(ApiProblemResponseListener::class, $listener);

        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/v1/transactions'),
            HttpKernelInterface::MAIN_REQUEST,
            new InvalidAmountInput('/amount/value', 'amount.not_canonical'),
        );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('content-type'));
        self::assertJsonStringEqualsJsonString(
            '{"type":"/problems/amount.not_canonical","title":"Montant invalide","status":422,"detail":"Le montant doit contenir une valeur décimale canonique et un code d’actif connus, dans les limites de précision autorisées.","pointer":"/amount/value"}',
            (string) $response->getContent(),
        );
    }

    #[DataProvider('thrownErrors')]
    public function testEachErrorKeepsItsStatusUnderAMatchingTitle(\Throwable $error, int $status, string $title, ?string $retryAfter = null): void
    {
        $kernel = self::bootKernel();
        $listener = self::getContainer()->get(ApiProblemResponseListener::class);
        self::assertInstanceOf(ApiProblemResponseListener::class, $listener);

        $event = new ExceptionEvent($kernel, Request::create('/api/v1/transactions'), HttpKernelInterface::MAIN_REQUEST, $error);

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('content-type'));
        $problem = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame($title, $problem['title']);
        self::assertSame($status, $problem['status']);
        self::assertIsString($problem['detail']);
        self::assertStringNotContainsString('private cause', $problem['detail']);
        self::assertStringNotContainsString('api.problem.', $problem['detail']);
        self::assertSame($retryAfter, $response->headers->get('retry-after'));
        self::assertFalse($response->headers->has('x-private'));
    }

    /** @return iterable<string, array{0: \Throwable, 1: int, 2: string, 3?: string}> */
    public static function thrownErrors(): iterable
    {
        $message = 'private cause';

        yield '400' => [new BadRequestHttpException($message), 400, 'Requête invalide'];
        yield '401' => [new UnauthorizedHttpException('Bearer', $message), 401, 'Authentification requise'];
        yield '403' => [new AccessDeniedHttpException($message), 403, 'Accès refusé'];
        yield '404' => [new NotFoundHttpException($message), 404, 'Ressource introuvable'];
        yield '405' => [new MethodNotAllowedHttpException(['GET'], $message), 405, 'Méthode non autorisée'];
        yield '406' => [new NotAcceptableHttpException($message), 406, 'Format non disponible'];
        yield '409' => [new ConflictHttpException($message), 409, 'Conflit'];
        yield '413' => [new HttpException(413, $message), 413, 'Requête trop volumineuse'];
        yield '415' => [new UnsupportedMediaTypeHttpException($message), 415, 'Type de média non supporté'];
        yield '422' => [new UnprocessableEntityHttpException($message), 422, 'Requête non traitable'];
        yield '429' => [new TooManyRequestsHttpException(30, $message, headers: ['X-Private' => $message]), 429, 'Trop de requêtes', '30'];
        yield '429 without a delay' => [new TooManyRequestsHttpException(message: $message), 429, 'Trop de requêtes'];
        yield 'unmapped 4xx' => [new HttpException(418, $message, headers: ['Retry-After' => '30']), 418, 'Requête refusée'];
        yield '503' => [new HttpException(503, $message), 503, 'Erreur interne du serveur'];
        yield 'not an HTTP error' => [new \RuntimeException($message), 500, 'Erreur interne du serveur'];
    }
}
