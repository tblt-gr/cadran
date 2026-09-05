<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\DuplicateProductModel;
use App\Module\Accounts\Application\ProductModelArchived;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DuplicateProductModelTest extends TestCase
{
    /**
     * Archiving stops new use. Duplication is new use, so it must lock the
     * source the way archive does: an unlocked read can still copy a model
     * another transaction has already retired.
     */
    public function testAConcurrentArchiveIsSeenBeforeTheCopyStarts(): void
    {
        $duplicate = $this->duplicate(new ArchiveOnLockProductModelRepository(ProductModelFixture::model()));

        $this->expectException(ProductModelArchived::class);
        $this->expectExceptionMessage('An archived model cannot start a new one.');

        $duplicate(ProductModelFixture::ID, 'Livret Banque X (copie)');
    }

    private function duplicate(ProductModelRepository $models): DuplicateProductModel
    {
        return new DuplicateProductModel(
            new FixedCallerWorkspace(ProductModelFixture::WORKSPACE),
            $models,
            new SequenceUuidGenerator(),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent(new CollectingAuditEventRepository(), new SequenceUuidGenerator()),
            new MockClock(ProductModelFixture::NOW),
        );
    }
}

/**
 * Simulates archive winning the race: an unlocked read still sees the active
 * snapshot, while locking waits and then observes the archived row.
 */
final class ArchiveOnLockProductModelRepository implements ProductModelRepository
{
    public function __construct(private readonly ProductModel $source)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        if ($this->source->id !== $id || !$this->source->workspace->equals($workspace)) {
            return null;
        }

        return $this->source;
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?ProductModel
    {
        $current = $this->find($workspace, $id);
        if (null === $current || $current->isArchived()) {
            return $current;
        }

        return $current->archive(new \DateTimeImmutable('2026-09-05T09:00:00+00:00'));
    }

    public function list(WorkspaceScope $workspace, bool $includeArchived, int $limit, int $offset): array
    {
        return [];
    }

    public function count(WorkspaceScope $workspace, bool $includeArchived): int
    {
        return 0;
    }

    public function hasActiveName(WorkspaceScope $workspace, string $name, ?string $excludingId = null): bool
    {
        return false;
    }

    public function add(ProductModel $model): void
    {
    }

    public function update(ProductModel $model, int $expectedVersion): bool
    {
        return false;
    }
}
