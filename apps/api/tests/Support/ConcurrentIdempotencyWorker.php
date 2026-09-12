<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use App\Module\Transactions\Application\DuplicateTransaction;
use App\Module\Transactions\Application\IdempotencyRequest;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Tests\Support\WorkspaceFixture;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__).'/bootstrap.php';

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';

[$script, $case, $key, $originalId, $barrier, $worker] = $argv;
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer();
$tokens = $container->get(TokenStorageInterface::class);
$tokens->setToken(new UsernamePasswordToken(new SecurityUser(WorkspaceFixture::OWNER_EMAIL, 'irrelevant-hash', false), 'main'));
$execution = $container->get(IdempotentExecution::class);

file_put_contents($barrier.'.ready.'.$worker, '');
while (2 !== count(glob($barrier.'.ready.*') ?: [])) {
    usleep(1_000);
}

$result = $execution->execute(
    match ($case) {
        'create' => 'transaction.create',
        'duplicate' => 'transaction.duplicate',
        'refund' => 'refund.create',
        'transfer' => 'transfer.create',
    },
    new IdempotencyRequest($key, hash('sha256', $case)),
    function () use ($case, $container, $originalId, $barrier): IdempotentResponse {
        // Only the winning request reaches this closure. Holding it here gives
        // the sibling process time to contend for the exact persisted claim.
        file_put_contents($barrier.'.owner', '');
        while (!file_exists($barrier.'.release')) {
            usleep(1_000);
        }

        match ($case) {
            'create' => $container->get(CreateTransaction::class)(new CreateTransactionInput(
                '00000000-0000-7000-8000-0000000000d1', ['value' => '-4.20', 'assetCode' => 'EUR'], 'EXPENSE', 'BOOKED',
                '2026-03-14', null, null, 'Concurrent idempotent create', null, null, null, null, null, null, null, null,
            )),
            'duplicate' => $container->get(DuplicateTransaction::class)($originalId),
            'refund' => $container->get(CreateRefund::class)($originalId, new CreateRefundInput(
                '00000000-0000-7000-8000-0000000000d1', ['value' => '1.00', 'assetCode' => 'EUR'], '2026-03-14',
                'Concurrent idempotent refund', null, null, null,
            )),
            'transfer' => $container->get(CreateTransfer::class)(new CreateTransferInput(
                '00000000-0000-7000-8000-0000000000d1', '00000000-0000-7000-8000-0000000000d2',
                ['value' => '5.00', 'assetCode' => 'EUR'], null, 'BOOKED', '2026-03-14', null,
                'Concurrent idempotent transfer', null, null,
            )),
        };

        return new IdempotentResponse(['operation' => $case], 201, null);
    },
);

echo json_encode(['status' => $result->status, 'replayed' => $result->replayed], JSON_THROW_ON_ERROR);
$kernel->shutdown();
