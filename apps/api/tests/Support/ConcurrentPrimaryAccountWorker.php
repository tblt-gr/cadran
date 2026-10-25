<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';
require dirname(__DIR__).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 4 !== count($arguments)) {
    throw new RuntimeException('Expected account, barrier and worker arguments.');
}
[, $accountId, $barrier, $worker] = array_map(static function (mixed $value): string {
    if (!is_string($value)) {
        throw new RuntimeException('Expected string arguments.');
    }

    return $value;
}, $arguments);
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new RuntimeException('Test container unavailable.');
}
$connection = $container->get(Connection::class);
if (!$connection instanceof Connection) {
    throw new RuntimeException('Connection unavailable.');
}
$accounts = new DbalAccountRepository($connection);
file_put_contents($barrier.'.ready.'.$worker, '');
$deadline = microtime(true) + 10;
while (2 !== count(glob($barrier.'.ready.*') ?: [])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(1_000);
}
$connection->transactional(function () use ($accounts, $accountId): void {
    $account = $accounts->findForUpdate(WorkspaceFixture::own(), $accountId);
    if (null === $account || !$accounts->update($account->withPrimary(true, new DateTimeImmutable()), $account->version)) {
        throw new RuntimeException('Primary switch failed.');
    }
});
echo 'ok';
$kernel->shutdown();
