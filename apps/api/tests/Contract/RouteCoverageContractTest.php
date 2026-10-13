<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Rule 1 of the API contract review: the router and the OpenAPI document describe the same set
 * of method and path pairs, path parameters being compared by position and not by name.
 */
final class RouteCoverageContractTest extends KernelTestCase
{
    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function testEveryRouteHasOneOperationAndEveryOperationHasARoute(): void
    {
        $routes = self::routerOperations();
        $documented = self::documentedOperations();

        self::assertNotEmpty($routes);
        self::assertSame([], array_values(array_diff($routes, $documented)), 'Routes without an OpenAPI operation.');
        self::assertSame([], array_values(array_diff($documented, $routes)), 'OpenAPI operations without a route.');
    }

    /** @return list<string> */
    private static function routerOperations(): array
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);

        $operations = [];
        foreach ($router->getRouteCollection() as $route) {
            if (!str_starts_with($route->getPath(), '/api/v1')) {
                continue;
            }
            $methods = $route->getMethods();
            self::assertNotSame([], $methods, sprintf('Route %s must restrict its HTTP methods.', $route->getPath()));
            foreach ($methods as $method) {
                $operations[] = self::key($method, $route->getPath());
            }
        }

        self::assertSame(array_values(array_unique($operations)), $operations, 'A method and path pair is routed twice.');
        sort($operations);

        return $operations;
    }

    /** @return list<string> */
    private static function documentedOperations(): array
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml');
        self::assertIsArray($document);
        $paths = $document['paths'] ?? null;
        self::assertIsArray($paths);

        $operations = [];
        foreach ($paths as $path => $item) {
            self::assertIsString($path);
            self::assertIsArray($item);
            if (!str_starts_with($path, '/api/v1')) {
                continue;
            }
            foreach (array_keys($item) as $method) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $operations[] = self::key((string) $method, $path);
                }
            }
        }

        self::assertSame(array_values(array_unique($operations)), $operations);
        sort($operations);

        return $operations;
    }

    private static function key(string $method, string $path): string
    {
        return strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
