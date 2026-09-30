<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Budget\Application\Goals\GoalConflict;
use App\Module\Budget\Application\Goals\UpdateGoal;
use App\Module\Budget\Application\Goals\UpdateGoalInput;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';

require dirname(__DIR__).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 5 !== count($arguments)) {
    throw new RuntimeException('Usage: ConcurrentGoalUpdateWorker.php <goalId> <amount> <barrier> <worker>');
}
[, $goalId, $amount, $barrier, $worker] = array_map(static function (mixed $value): string {
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
$container->set(ClockInterface::class, new MockClock('2026-09-14T09:12:04+00:00'));
$update = $container->get(UpdateGoal::class);
if (!$update instanceof UpdateGoal) {
    throw new RuntimeException('UpdateGoal is unavailable.');
}

file_put_contents($barrier.'.ready.'.$worker, '');
while (2 !== count(glob($barrier.'.ready.*') ?: [])) {
    usleep(1_000);
}
file_put_contents($barrier.'.calling.'.$worker, '');

try {
    $update(new UpdateGoalInput($goalId, 1, 'Concurrent update '.$worker, $amount, 'EUR', null, false));
    $outcome = 'ok';
} catch (GoalConflict $exception) {
    $outcome = $exception->errorCode;
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
$kernel->shutdown();
