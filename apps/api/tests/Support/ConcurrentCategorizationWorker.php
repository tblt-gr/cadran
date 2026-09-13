<?php

declare(strict_types=1);

use App\Kernel;
use App\Module\Identity\Infrastructure\Security\SecurityUser;
use App\Module\Transactions\Application\Categorization\ApplyCategorizationRules;
use App\Module\Transactions\Application\Categorization\CategorizationRuleInput;
use App\Module\Transactions\Application\Categorization\UpdateCategorizationRule;
use App\Module\Transactions\Application\ReplaceTransactionSplits;
use App\Module\Transactions\Application\ReplaceTransactionSplitsInput;
use App\Module\Transactions\Application\UpdateTransaction;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

$_SERVER['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = '1';
$_SERVER['APP_SECRET'] = 'test-only-secret';

require dirname(__DIR__).'/bootstrap.php';

$arguments = $_SERVER['argv'] ?? null;
if (!is_array($arguments) || 4 !== count($arguments)) {
    throw new RuntimeException('Usage: ConcurrentCategorizationWorker.php <case> <argument> <barrier>');
}
[, $case, $argument, $barrier] = array_map(static function (mixed $value): string {
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
$connection = $container->get(Connection::class);
if (!$connection instanceof Connection) {
    throw new RuntimeException('DBAL connection is unavailable.');
}
$connection->executeStatement("SET lock_timeout = '150ms'");

file_put_contents($barrier.'.ready', '');
while (!file_exists($barrier.'.release')) {
    usleep(1_000);
}

try {
    if ('apply' === $case) {
        $operation = $container->get(ApplyCategorizationRules::class);
        if (!$operation instanceof ApplyCategorizationRules) {
            throw new RuntimeException('ApplyCategorizationRules is unavailable.');
        }
        $operation($argument);
    } elseif ('manual' === $case) {
        $operation = $container->get(ReplaceTransactionSplits::class);
        if (!$operation instanceof ReplaceTransactionSplits) {
            throw new RuntimeException('ReplaceTransactionSplits is unavailable.');
        }
        $operation($argument, new ReplaceTransactionSplitsInput([[
            'categoryId' => '00000000-0000-7000-8000-0000000000c4',
            'amount' => ['value' => '-42.90', 'assetCode' => 'EUR'],
            'analyticAxes' => [],
            'note' => null,
        ]], 1));
    } elseif ('move' === $case) {
        $operation = $container->get(UpdateTransaction::class);
        if (!$operation instanceof UpdateTransaction) {
            throw new RuntimeException('UpdateTransaction is unavailable.');
        }
        $operation($argument, new UpdateTransactionInput(
            '00000000-0000-7000-8000-0000000000d1', ['value' => '-5.00', 'assetCode' => 'EUR'],
            'EXPENSE', 'BOOKED', '2026-02-15', null, null, 'CB CARREFOUR LATER', null, null,
            null, null, null, null, null, null, 1,
        ));
    } elseif ('rule' === $case) {
        $operation = $container->get(UpdateCategorizationRule::class);
        if (!$operation instanceof UpdateCategorizationRule) {
            throw new RuntimeException('UpdateCategorizationRule is unavailable.');
        }
        $operation($argument, new CategorizationRuleInput(
            'Concurrent rule edit', 5, [],
            ['rawLabel' => ['operator' => 'CONTAINS', 'value' => 'carrefour']],
            '00000000-0000-7000-8000-0000000000c1', ['ESSENTIAL'], null,
            '2026-01-01', null, true, 1,
        ));
    } elseif ('reference' === $case) {
        $connection->insert('category_categories', [
            'id' => $argument, 'workspace_id' => WorkspaceFixture::OWN_WORKSPACE, 'type' => 'EXPENSE',
            'label' => 'Concurrent reference', 'parent_id' => null, 'icon' => null, 'color' => null,
            'default_analytic_axes' => '[]', 'budget_included' => true, 'sort_order' => 0, 'depth' => 1,
            'version' => 1, 'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
        ]);
    } else {
        throw new InvalidArgumentException(sprintf('Unknown case "%s".', $case));
    }
    $result = 'completed';
} catch (Throwable $exception) {
    $result = str_contains($exception->getMessage(), 'lock timeout') ? 'blocked' : $exception::class.': '.$exception->getMessage();
}

echo json_encode(['result' => $result], JSON_THROW_ON_ERROR);
$kernel->shutdown();
