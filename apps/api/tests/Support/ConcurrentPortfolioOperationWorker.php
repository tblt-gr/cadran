<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\CorrectPortfolioOperation;
use App\Module\Portfolio\Application\OperationInput;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\VoidPortfolioOperation;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';

require dirname(__DIR__).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 5 !== count($arguments)) {
    throw new RuntimeException('Usage: ConcurrentPortfolioOperationWorker.php <case> <operationId> <barrier> <worker>');
}
[, $case, $operationId, $barrier, $worker] = array_map(static function (mixed $value): string {
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

$deposit = static fn (string $amount): OperationInput => new OperationInput(
    type: 'DEPOSIT', tradeDate: '2026-03-02', grossAmount: new AmountInput($amount, 'EUR'),
);

file_put_contents($barrier.'.ready.'.$worker, '');
while (2 !== count(glob($barrier.'.ready.*') ?: [])) {
    usleep(1_000);
}

try {
    $outcome = match ($case) {
        'create' => (static function () use ($container, $deposit, $worker): string {
            $record = $container->get(RecordPortfolioOperation::class);
            \assert($record instanceof RecordPortfolioOperation);

            return 'created:'.$record(PortfolioFixture::ACCOUNT, $deposit('10.0'.$worker))->sequence;
        })(),
        'void' => (static function () use ($container, $operationId): string {
            $void = $container->get(VoidPortfolioOperation::class);
            \assert($void instanceof VoidPortfolioOperation);
            $void($operationId, 1, 'Concurrent void');

            return 'voided';
        })(),
        'correct' => (static function () use ($container, $operationId, $deposit, $worker): string {
            $correct = $container->get(CorrectPortfolioOperation::class);
            \assert($correct instanceof CorrectPortfolioOperation);
            $correct($operationId, 1, null, $deposit('20.0'.$worker));

            return 'corrected';
        })(),
        default => throw new InvalidArgumentException(sprintf('Unknown case "%s".', $case)),
    };
} catch (InvalidPortfolioOperation $exception) {
    $outcome = 'refused:'.$exception->problemCode;
} catch (Throwable $exception) {
    $outcome = 'refused:'.(new ReflectionClass($exception))->getShortName();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
$kernel->shutdown();
