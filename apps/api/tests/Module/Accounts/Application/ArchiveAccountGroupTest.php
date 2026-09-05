<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\ArchiveAccountGroup;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ArchiveAccountGroupTest extends TestCase
{
    public function testAnArchivedChildDoesNotBlockArchivingItsParent(): void
    {
        $parent = AccountGroupFixture::group();
        $child = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Livret',
            parentId: $parent->id,
            depth: 2,
            archivedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
        );
        $groups = new InMemoryAccountGroupRepository($parent, $child);
        $trail = new CollectingAuditEventRepository();

        $archived = (new ArchiveAccountGroup(
            new FixedCallerWorkspace(AccountGroupFixture::WORKSPACE),
            $groups,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 10:00:00'),
        ))($parent->id, 1);

        self::assertNotNull($archived->archivedAt);
        self::assertNotNull($groups->find(
            WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE),
            $parent->id,
        )?->archivedAt);
    }
}
