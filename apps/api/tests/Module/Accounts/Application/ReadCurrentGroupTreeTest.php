<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\ReadCurrentGroupTree;
use App\Module\Accounts\Application\ReadReportReferences;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;

final class ReadCurrentGroupTreeTest extends TestCase
{
    private const string BOURSE = '00000000-0000-7000-8000-0000000000c1';
    private const string IMMOBILIER = '00000000-0000-7000-8000-0000000000c2';
    private const string ARCHIVED = '00000000-0000-7000-8000-0000000000c3';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string FOREIGN_GROUP = '00000000-0000-7000-8000-0000000000d2';

    public function testItReadsActiveLineagesDepthFirstAndEveryAccountPrimaryGroup(): void
    {
        $now = new \DateTimeImmutable('2026-09-05T10:00:00+00:00');
        $account = AccountFixture::account()->regroup(self::BOURSE, $now);
        $tree = ($this->reader([$account]))(WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE));

        self::assertEquals([
            new GroupLineage(AccountGroupFixture::ID, [AccountGroupFixture::ID]),
            new GroupLineage(self::BOURSE, [self::BOURSE, AccountGroupFixture::ID]),
            new GroupLineage(self::IMMOBILIER, [self::IMMOBILIER, AccountGroupFixture::ID]),
        ], $tree->lineages);
        self::assertSame([AccountFixture::ID => self::BOURSE], $tree->primaryGroupByAccount);
    }

    public function testNothingOfAnotherWorkspaceIsRead(): void
    {
        $foreign = AccountFixture::account(id: self::FOREIGN_ACCOUNT, workspace: AccountGroupFixture::OTHER_WORKSPACE)
            ->regroup(self::FOREIGN_GROUP, new \DateTimeImmutable('2026-09-05T10:00:00+00:00'));

        $tree = ($this->reader([$foreign]))(WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE));

        self::assertSame([], $tree->primaryGroupByAccount);
        foreach ($tree->lineages as $lineage) {
            self::assertNotSame(self::FOREIGN_GROUP, $lineage->groupId);
        }
    }

    public function testReportReferencesLabelEachGroupByItsOwnNameWithItsParentLabelInDepthFirstOrder(): void
    {
        $references = (new ReadReportReferences(new InMemoryAccountRepository(), $this->tree()))(WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE));

        self::assertSame([
            AccountGroupFixture::ID => 'Investissement',
            self::BOURSE => 'Bourse',
            self::IMMOBILIER => 'Immobilier',
            self::ARCHIVED => 'Ancien',
        ], $references->groups);
        self::assertSame([
            AccountGroupFixture::ID => null,
            self::BOURSE => 'Investissement',
            self::IMMOBILIER => 'Investissement',
            self::ARCHIVED => 'Investissement',
        ], $references->groupParents);
        // Every group of the workspace resolves a color, none from another workspace.
        self::assertEqualsCanonicalizing(array_keys($references->groups), array_keys($references->groupColors));
        self::assertArrayNotHasKey(self::FOREIGN_GROUP, $references->groupColors);
    }

    /** @param list<\App\Module\Accounts\Domain\Account> $accounts */
    private function reader(array $accounts): ReadCurrentGroupTree
    {
        return new ReadCurrentGroupTree(new InMemoryAccountRepository(...$accounts), $this->tree());
    }

    private function tree(): InMemoryAccountGroupRepository
    {
        return new InMemoryAccountGroupRepository(
            AccountGroupFixture::group(label: 'Investissement'),
            AccountGroupFixture::group(self::IMMOBILIER, label: 'Immobilier', parentId: AccountGroupFixture::ID, depth: 2, sortOrder: 1),
            AccountGroupFixture::group(self::BOURSE, label: 'Bourse', parentId: AccountGroupFixture::ID, depth: 2, sortOrder: 0),
            AccountGroupFixture::group(self::ARCHIVED, label: 'Ancien', parentId: AccountGroupFixture::ID, depth: 2, archivedAt: new \DateTimeImmutable('2026-09-06T10:00:00+00:00'), sortOrder: 2),
            AccountGroupFixture::group(self::FOREIGN_GROUP, AccountGroupFixture::OTHER_WORKSPACE, 'Foreign'),
        );
    }
}
