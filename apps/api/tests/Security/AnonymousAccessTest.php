<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * ASVS 5.0 V8 (authorization): the API is closed by default. The routes are read from the router, so a controller
 * added tomorrow is covered without editing this test: every /api/v1 operation, except the status probe and the
 * public operations of the session resource, answers an anonymous caller with a 401 problem.
 */
final class AnonymousAccessTest extends WebTestCase
{
    /**
     * The status probe, the session resource, and the one-shot first-run definition of the owner password: it has
     * no session to rely on and refuses (409) once a password exists.
     *
     * @var list<string>
     */
    private const array PUBLIC_OPERATIONS = [
        'GET /api/v1/status',
        'GET /api/v1/session',
        'POST /api/v1/session',
        'DELETE /api/v1/session',
        'PUT /api/v1/session/password',
    ];

    public function testEveryOtherOperationRefusesAnAnonymousCaller(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status');
        $csrf = $client->getCookieJar()->get(SignedCsrfToken::COOKIE_NAME);
        self::assertNotNull($csrf);

        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $checked = 0;
        foreach ($router->getRouteCollection() as $route) {
            if (!str_starts_with($route->getPath(), '/api/v1')) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $path = self::concretePath($route);
                if (in_array($method.' '.$route->getPath(), self::PUBLIC_OPERATIONS, true)) {
                    continue;
                }
                $client->request($method, $path, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf->getValue()], content: '{}');

                $operation = $method.' '.$route->getPath();
                self::assertSame(401, $client->getResponse()->getStatusCode(), $operation.' must answer an anonymous caller with 401.');
                self::assertStringStartsWith('application/problem+json', (string) $client->getResponse()->headers->get('Content-Type'), $operation);
                ++$checked;
            }
        }

        self::assertGreaterThan(100, $checked, 'The router walk found too few operations to be the whole API.');
    }

    private static function concretePath(Route $route): string
    {
        $path = $route->getPath();
        foreach ($route->compile()->getPathVariables() as $variable) {
            self::assertIsString($variable);
            $name = $variable;
            $path = str_replace('{'.$name.'}', self::valueFor($route->getRequirement($name)), $path);
        }

        return $path;
    }

    private static function valueFor(?string $requirement): string
    {
        if (null !== $requirement && 1 === preg_match('/^[\w-]+(?:\|[\w-]+)*$/', $requirement)) {
            return explode('|', $requirement)[0];
        }

        foreach (['00000000-0000-7000-8000-000000000001', '2026', '2026-03', 'a'] as $candidate) {
            if (null === $requirement || 1 === preg_match('#^(?:'.$requirement.')$#', $candidate)) {
                return $candidate;
            }
        }

        throw new \LogicException('No sample value fits the route requirement '.$requirement);
    }
}
