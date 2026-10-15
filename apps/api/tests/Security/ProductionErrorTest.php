<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * ASVS 5.0 V16 (error handling): in the deployed `prod` environment (debug off), an unhandled exception answers a
 * generic RFC 9457 problem. The exception message below holds a file path, a class name and SQL text on purpose:
 * none of them may reach the response.
 */
final class ProductionErrorTest extends TestCase
{
    private const string LEAKY_MESSAGE = 'SQLSTATE[42P01]: Undefined table in /app/apps/api/src/Module/Transactions/Infrastructure/Persistence/DbalTransactionRepository.php: SELECT label, amount FROM transaction_transactions WHERE label = \'Zx9-sentinel-label\'';

    private string $cacheDirectory;
    private string $errorLog;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        // A throwaway cache: the prod container never re-checks freshness, so a shared var/cache/prod could serve a stale one.
        $this->cacheDirectory = sys_get_temp_dir().'/cadran-prod-error-test-'.bin2hex(random_bytes(6));
        $this->errorLog = $this->cacheDirectory.'.log';
        $this->previousErrorLog = ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        self::remove($this->cacheDirectory);
        if (is_file($this->errorLog)) {
            unlink($this->errorLog);
        }
        if (false !== $this->previousErrorLog) {
            ini_set('error_log', $this->previousErrorLog);
        }
    }

    public function testAnUnhandledExceptionAnswersAGenericProblemWithoutInternals(): void
    {
        $response = $this->handle('/api/v1/status', throwing: new \RuntimeException(self::LEAKY_MESSAGE));

        self::assertSame(500, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        self::assertNull($response->headers->get('X-Debug-Token'));

        $body = (string) $response->getContent();
        $problem = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame(500, $problem['status']);
        self::assertIsString($problem['title']);
        self::assertNotSame('', $problem['title']);

        foreach (['Zx9-sentinel-label', 'SQLSTATE', 'SELECT', 'transaction_transactions', 'RuntimeException', 'Exception', '/app/', '.php', 'DbalTransactionRepository', 'trace', '#0', 'vendor/'] as $internal) {
            self::assertStringNotContainsString($internal, $body, 'The problem detail leaks: '.$internal);
        }
    }

    public function testAnUnknownApiRouteAnswersAProblemWithoutInternals(): void
    {
        $response = $this->handle('/api/v1/does-not-exist');

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/problem+json', (string) $response->headers->get('Content-Type'));
        $body = (string) $response->getContent();
        foreach (['NotFoundHttpException', 'No route found', '.php', 'trace', '#0'] as $internal) {
            self::assertStringNotContainsString($internal, $body, 'The problem detail leaks: '.$internal);
        }
    }

    private static function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }

    private function handle(string $path, ?\Throwable $throwing = null): Response
    {
        $kernel = new class('prod', false, $this->cacheDirectory) extends Kernel {
            public function __construct(string $environment, bool $debug, private readonly string $cacheDirectory)
            {
                parent::__construct($environment, $debug);
            }

            public function getCacheDir(): string
            {
                return $this->cacheDirectory;
            }
        };
        $kernel->boot();

        if (null !== $throwing) {
            $dispatcher = $kernel->getContainer()->get('event_dispatcher');
            self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
            $dispatcher->addListener(KernelEvents::REQUEST, static function () use ($throwing): never {
                throw $throwing;
            }, 1000);
        }

        try {
            return $kernel->handle(Request::create($path), HttpKernelInterface::MAIN_REQUEST, true);
        } finally {
            $kernel->shutdown();
        }
    }
}
