<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalance;
use App\Module\Transactions\Application\Reconciliation\ReconcileAccountBalanceInput;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';

require dirname(__DIR__).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 7 !== count($arguments)) {
    throw new RuntimeException('Usage: ConcurrentAccountReconciliationWorker.php <accountId> <snapshotId> <periodStart> <resolution> <barrier> <worker>');
}

[, $accountId, $snapshotId, $periodStart, $resolution, $barrier, $worker] = array_map(static function (mixed $value): string {
    if (!is_string($value)) {
        throw new RuntimeException('Expected a string argv entry.');
    }

    return $value;
}, $arguments);

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
if (!$container instanceof ContainerInterface) {
    throw new RuntimeException('Test service container is unavailable.');
}
$tokens = $container->get(TokenStorageInterface::class);
if (!$tokens instanceof TokenStorageInterface) {
    throw new RuntimeException('Token storage is unavailable.');
}
$tokens->setToken(new UsernamePasswordToken(new SecurityUser(WorkspaceFixture::OWNER_EMAIL, 'irrelevant-hash', false), 'main'));
$reconcile = $container->get(ReconcileAccountBalance::class);
if (!$reconcile instanceof ReconcileAccountBalance) {
    throw new RuntimeException('ReconcileAccountBalance service is unavailable.');
}

file_put_contents($barrier.'.ready.'.$worker, '');
while (2 !== count(glob($barrier.'.ready.*') ?: [])) {
    usleep(1_000);
}

try {
    $reconcile($accountId, new ReconcileAccountBalanceInput($snapshotId, 1, $periodStart, $resolution));
    $outcome = 'ok';
} catch (Throwable $exception) {
    $outcome = $exception::class;
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
$kernel->shutdown();
