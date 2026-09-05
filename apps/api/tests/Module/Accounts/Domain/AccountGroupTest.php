<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Accounts\Domain\InvalidAccountGroup;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountGroupTest extends TestCase
{
    public function testItCarriesAWorkspaceScopedLabelAndOptionalParent(): void
    {
        $group = $this->group();

        self::assertSame('Épargne', $group->label);
        self::assertNull($group->parentId);
        self::assertSame(1, $group->depth);
        self::assertSame(0, $group->sortOrder);
    }

    public function testAChildCannotBeItsOwnParent(): void
    {
        $this->expectException(InvalidAccountGroup::class);
        $this->expectExceptionMessage('own parent');

        $this->group(
            id: '00000000-0000-7000-8000-0000000000b1',
            parentId: '00000000-0000-7000-8000-0000000000b1',
            depth: 2,
        );
    }

    public function testTheTreeDepthIsBounded(): void
    {
        $this->expectException(InvalidAccountGroup::class);

        $this->group(depth: AccountGroup::MAX_TREE_DEPTH + 1);
    }

    #[DataProvider('invalidLabels')]
    public function testItRejectsAnUnboundedOrExecutableLabel(string $label): void
    {
        $this->expectException(InvalidAccountGroup::class);

        $this->group(label: $label);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLabels(): iterable
    {
        yield 'empty' => [''];
        yield 'untrimmed' => [' Épargne '];
        yield 'control character' => ["Épar\ngne"];
        yield 'too long' => [str_repeat('a', AccountGroup::MAX_LABEL_LENGTH + 1)];
    }

    public function testAnArchivedGroupIsReadOnly(): void
    {
        $group = $this->group(archivedAt: new \DateTimeImmutable());

        $this->expectException(InvalidAccountGroup::class);
        $this->expectExceptionMessage('archived group');

        $group->reconfigure(
            label: 'Liquidités',
            parentId: $group->parentId,
            sortOrder: $group->sortOrder,
            depth: $group->depth,
            updatedAt: new \DateTimeImmutable(),
        );
    }

    public function testReparentingRecordsTheNewDepth(): void
    {
        $moved = $this->group()->reconfigure(
            label: 'Épargne',
            parentId: '00000000-0000-7000-8000-0000000000b2',
            sortOrder: 5,
            depth: 2,
            updatedAt: new \DateTimeImmutable('2026-09-05T10:00:00+00:00'),
        );

        self::assertSame('00000000-0000-7000-8000-0000000000b2', $moved->parentId);
        self::assertSame(2, $moved->depth);
        self::assertSame(5, $moved->sortOrder);
        self::assertSame(2, $moved->version);
    }

    public function testRebaseDepthKeepsAnArchivedDescendantConsistent(): void
    {
        $archived = $this->group(archivedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'));
        $rebased = $archived->rebaseDepth(4, new \DateTimeImmutable('2026-09-05T10:00:00+00:00'));

        self::assertSame(4, $rebased->depth);
        self::assertNotNull($rebased->archivedAt);
        self::assertSame(2, $rebased->version);
    }

    private function group(
        string $id = '00000000-0000-7000-8000-0000000000b1',
        string $label = 'Épargne',
        ?string $parentId = null,
        int $depth = 1,
        ?\DateTimeImmutable $archivedAt = null,
    ): AccountGroup {
        $now = new \DateTimeImmutable('2026-09-05T10:00:00+00:00');

        return new AccountGroup(
            id: $id,
            workspace: WorkspaceScope::fromString('00000000-0000-7000-8000-0000000000a1'),
            label: $label,
            parentId: $parentId,
            sortOrder: 0,
            depth: $depth,
            version: 1,
            createdAt: $now,
            updatedAt: $now,
            archivedAt: $archivedAt,
        );
    }
}
