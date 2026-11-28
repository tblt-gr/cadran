<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\BulkEditChanges;
use App\Module\Transactions\Application\BulkEditInput;
use App\Module\Transactions\Application\BulkEditTransactions;
use App\Module\Transactions\Application\BulkFieldChange;
use App\Module\Transactions\Application\Categorization\ApplyCategorizationRules;
use App\Module\Transactions\Application\Categorization\PreviewStale;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\Recurrence\ArchiveRecurrence;
use App\Module\Transactions\Application\Recurrence\EditRecurrence;
use App\Module\Transactions\Application\Recurrence\MaterializeRecurrenceOccurrences;
use App\Module\Transactions\Application\Recurrence\RecurrenceEditInput;
use App\Module\Transactions\Application\Recurrence\RefreshOccurrenceHorizon;
use App\Module\Transactions\Application\Recurrence\RestoreRecurrence;
use App\Module\Transactions\Application\Recurrence\StaleRecurrence;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\StaleTransferVersion;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransferConflict;
use App\Module\Transactions\Application\UpdateTransaction;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Module\Transactions\Application\UpdateTransfer;
use App\Module\Transactions\Application\UpdateTransferInput;
use App\Module\Transactions\Application\VoidTransfer;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
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
if (!is_array($arguments) || 6 !== count($arguments)) {
    throw new RuntimeException('Usage: ConcurrentLockOrderWorker.php <case> <json> <barrier> <worker> <parties>');
}
[, $case, $json, $barrier, $worker, $parties] = array_map(static function (mixed $value): string {
    if (!is_string($value)) {
        throw new RuntimeException('Expected a string argv entry.');
    }

    return $value;
}, $arguments);
/** @var array<string, mixed> $input */
$input = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$text = static function (string $key) use ($input): string {
    $value = $input[$key] ?? null;

    return is_string($value) ? $value : throw new RuntimeException(sprintf('"%s" is a string argument.', $key));
};
$number = static function (string $key) use ($input): int {
    $value = $input[$key] ?? null;

    return is_int($value) ? $value : throw new RuntimeException(sprintf('"%s" is an integer argument.', $key));
};

/**
 * @template T of object
 *
 * @param class-string<T> $class
 *
 * @return T
 */
function worker_service(ContainerInterface $container, string $class): object
{
    $service = $container->get($class);

    return $service instanceof $class ? $service : throw new RuntimeException($class.' is unavailable.');
}

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
$container->set(ClockInterface::class, new MockClock('2026-11-03T09:00:00+00:00'));
$connection = $container->get(Connection::class);
if (!$connection instanceof Connection) {
    throw new RuntimeException('DBAL connection is unavailable.');
}

$operation = match ($case) {
    'edit' => static function () use ($container, $text, $number): void {
        worker_service($container, EditRecurrence::class)($text('id'), new RecurrenceEditInput(
            'Loyer', null, '-850.00', '0.00', 'MONTHLY', 5, $number('version'), true,
        ));
    },
    'restore' => static function () use ($container, $text, $number): void {
        worker_service($container, RestoreRecurrence::class)($text('id'), $number('version'));
    },
    'archive' => static function () use ($container, $text, $number): void {
        worker_service($container, ArchiveRecurrence::class)($text('id'), $number('version'));
    },
    'materialize' => static function () use ($container): void {
        worker_service($container, MaterializeRecurrenceOccurrences::class)();
    },
    'refresh' => static function () use ($container): void {
        worker_service($container, RefreshOccurrenceHorizon::class)();
    },
    'refund' => static function () use ($container, $text): void {
        worker_service($container, CreateRefund::class)($text('id'), new CreateRefundInput(
            $text('accountId'), ['value' => '10.00', 'assetCode' => 'EUR'], '2026-11-03', 'Remboursement', null, null, null,
        ));
    },
    'apply' => static function () use ($container, $text): void {
        worker_service($container, ApplyCategorizationRules::class)($text('token'));
    },
    'update' => static function () use ($container, $text, $number): void {
        worker_service($container, UpdateTransaction::class)($text('id'), new UpdateTransactionInput(
            $text('accountId'), ['value' => '-850.00', 'assetCode' => 'EUR'], 'EXPENSE', 'BOOKED', '2026-11-03',
            null, null, 'Loyer', null, 'Updated', null, null, null, null, null, null, $number('version'),
        ));
    },
    'bulk' => static function () use ($container, $text): void {
        worker_service($container, BulkEditTransactions::class)(BulkEditInput::forIds(
            new BulkEditChanges(note: new BulkFieldChange('Bulk')), [$text('id')],
        ));
    },
    'updateTransfer' => static function () use ($container, $text, $number): void {
        worker_service($container, UpdateTransfer::class)($text('id'), new UpdateTransferInput(
            $text('sourceAccountId'), $text('targetAccountId'), 'BOOKED', '2026-11-02', null, 'Virement', 'Updated', null, $number('version'),
        ));
    },
    'voidTransfer' => static function () use ($container, $text, $number): void {
        worker_service($container, VoidTransfer::class)($text('id'), $number('version'));
    },
    default => throw new InvalidArgumentException(sprintf('Unknown case "%s".', $case)),
};

// The backend identifier lets the test watch this very connection wait on a lock.
$backend = $connection->fetchOne('SELECT pg_backend_pid()');
if (!is_int($backend) && !is_string($backend)) {
    throw new RuntimeException('The backend identifier is unavailable.');
}
file_put_contents($barrier.'.pid.'.$worker, (string) $backend);
file_put_contents($barrier.'.ready.'.$worker, '');
while ((int) $parties !== count(glob($barrier.'.ready.*') ?: [])) {
    usleep(1_000);
}

try {
    $operation();
    $outcome = 'completed';
} catch (StaleRecurrence|StaleTransactionVersion|StaleTransferVersion|TransactionConflict|TransferConflict|PreviewStale) {
    $outcome = 'refused';
} catch (Throwable $exception) {
    $outcome = $exception::class.': '.$exception->getMessage();
}

echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
$kernel->shutdown();
