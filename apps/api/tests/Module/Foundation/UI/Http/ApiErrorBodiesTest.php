<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Error bodies of the API are RFC 9457 problem details and never reveal an exception class, a
 * stack trace, a file path or an SQL fragment, even for an unexpected failure.
 */
final class ApiErrorBodiesTest extends WebTestCase
{
    private const array LEAK_MARKERS = [
        'Exception', 'Doctrine', 'Symfony', 'SQLSTATE', 'SELECT ', 'INSERT ', 'UPDATE ', ' FROM ',
        '.php', '/workspace', 'vendor/', '#0', 'Stack trace', '"trace"', '"class"', '"exception"',
    ];

    /** @return iterable<string, array{string, string, int}> */
    public static function clientErrors(): iterable
    {
        yield 'unknown route' => ['GET', '/api/v1/unknown', 404];
        yield 'anonymous read' => ['GET', '/api/v1/accounts', 401];
        yield 'method not allowed' => ['PUT', '/api/v1/status', 405];
        yield 'missing csrf token' => ['POST', '/api/v1/session', 403];
    }

    #[DataProvider('clientErrors')]
    public function testClientErrorsAreCleanProblemDetails(string $method, string $path, int $status): void
    {
        $client = self::createClient();
        $client->request($method, $path);

        self::assertResponseStatusCodeSame($status);
        self::assertCleanProblem((string) $client->getResponse()->getContent(), $client->getResponse()->headers->get('content-type'), $status);
    }

    public function testAnUnexpectedFailureAnswersAGenericProblemWithoutInternals(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(KernelEvents::REQUEST, static function (RequestEvent $event): void {
            if ('/api/v1/status' === $event->getRequest()->getPathInfo()) {
                throw new \PDOException('SQLSTATE[42P01]: relation "transaction" does not exist: SELECT amount FROM transaction in /workspace/apps/api/src/X.php');
            }
        }, 1000);

        $client->request('GET', '/api/v1/status');

        self::assertResponseStatusCodeSame(500);
        self::assertCleanProblem((string) $client->getResponse()->getContent(), $client->getResponse()->headers->get('content-type'), 500);
    }

    private static function assertCleanProblem(string $body, ?string $contentType, int $status): void
    {
        self::assertSame('application/problem+json', $contentType);
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame($status, $decoded['status'] ?? null);
        self::assertArrayHasKey('type', $decoded);
        self::assertArrayHasKey('title', $decoded);
        foreach (self::LEAK_MARKERS as $marker) {
            self::assertStringNotContainsString($marker, $body, sprintf('A problem body must not contain "%s".', $marker));
        }
    }
}
