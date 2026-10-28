<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\CreateAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroupInput;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\ReadGroupColors;
use App\Module\Accounts\Domain\AccountGroup;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CreateAccountGroupTest extends TestCase
{
    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testItCreatesARootGroupInTheCallerWorkspace(): void
    {
        $groups = new InMemoryAccountGroupRepository();
        $view = ($this->create($groups))(new CreateAccountGroupInput(
            label: 'Épargne',
            parentId: null,
        ));

        self::assertSame('Épargne', $view->label);
        self::assertNull($view->parentId);
        self::assertSame(1, $view->depth);
        self::assertSame('MISSING_VALUATION', $view->share->reason);
        self::assertNull($view->share->ratio);
        self::assertCount(1, $groups->list(
            \App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE),
            false,
            10,
            0,
        ));
    }

    public function testAParentFromAnotherWorkspaceIsRefused(): void
    {
        $groups = new InMemoryAccountGroupRepository(AccountGroupFixture::group(
            id: AccountGroupFixture::OTHER_ID,
            workspace: AccountGroupFixture::OTHER_WORKSPACE,
            label: 'Foreign',
        ));

        $this->expectException(InvalidAccountGroupInput::class);
        $this->expectExceptionMessage('parent');

        ($this->create($groups))(new CreateAccountGroupInput(
            label: 'Child',
            parentId: AccountGroupFixture::OTHER_ID,
        ));
    }

    public function testCreatingLeavesAnAuditEventThatNamesNoLabel(): void
    {
        ($this->create(new InMemoryAccountGroupRepository()))(new CreateAccountGroupInput(
            label: 'Épargne',
            parentId: null,
        ));

        self::assertCount(1, $this->trail->events);
        $event = $this->trail->events[0];
        self::assertSame('account_group.created', $event->eventType);
        $encoded = json_encode($event->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Épargne', $encoded);
        self::assertSame('false', $event->diff->after['hasParent']);
    }

    public function testAnActiveSiblingLabelIsRefused(): void
    {
        $groups = new InMemoryAccountGroupRepository(AccountGroupFixture::group());

        $this->expectException(AccountGroupConflict::class);

        ($this->create($groups))(new CreateAccountGroupInput(
            label: 'Épargne',
            parentId: null,
        ));
    }

    public function testASubGroupTakesTheSmallestSlotNoSiblingHoldsArchivedIncluded(): void
    {
        $root = AccountGroupFixture::group();
        $first = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'A', parentId: $root->id, depth: 2, colorSlot: 0);
        $archived = AccountGroupFixture::group(
            id: AccountGroupFixture::OTHER_ID,
            label: 'B',
            parentId: $root->id,
            depth: 2,
            archivedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            colorSlot: 1,
        );
        $third = AccountGroupFixture::group(id: AccountGroupFixture::GRANDCHILD_ID, label: 'D', parentId: $root->id, depth: 2, colorSlot: 3);
        $groups = new InMemoryAccountGroupRepository($root, $first, $archived, $third);

        $view = ($this->create($groups))(new CreateAccountGroupInput(label: 'C', parentId: $root->id));

        self::assertSame('AUTO', $view->colorMode);
        self::assertSame(\App\Module\Accounts\Domain\AccountGroupColor::shade($view->parentResolvedColor ?? '', 2), $view->resolvedColor);
        self::assertSame($view->resolvedColor, $view->defaultColor);
    }

    public function testANewGroupIsAppendedAfterTheLastSiblingArchivedOnesIncluded(): void
    {
        $first = AccountGroupFixture::group(id: AccountGroupFixture::ID, label: 'A', sortOrder: 0);
        $archived = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'B',
            archivedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            sortOrder: 5,
        );
        $otherParent = AccountGroupFixture::group(id: AccountGroupFixture::OTHER_ID, label: 'C', sortOrder: 40);
        $groups = new InMemoryAccountGroupRepository($first, $archived, $otherParent);

        $view = ($this->create($groups))(new CreateAccountGroupInput(label: 'D', parentId: null));
        $child = ($this->create($groups))(new CreateAccountGroupInput(label: 'E', parentId: $first->id));

        self::assertSame(41, $view->sortOrder);
        self::assertSame(0, $child->sortOrder);
    }

    public function testTheFirstGroupOfAnEmptyParentTakesPositionZero(): void
    {
        $view = ($this->create(new InMemoryAccountGroupRepository()))(new CreateAccountGroupInput(label: 'Root', parentId: null));

        self::assertSame(0, $view->sortOrder);
    }

    public function testSiblingsAreRenumberedWhenTheNextOrderWouldExceedTheCap(): void
    {
        $low = AccountGroupFixture::group(id: AccountGroupFixture::ID, label: 'A', sortOrder: 3);
        $top = AccountGroupFixture::group(id: AccountGroupFixture::CHILD_ID, label: 'B', sortOrder: AccountGroup::MAX_SORT_ORDER);
        $groups = new InMemoryAccountGroupRepository($low, $top);

        $view = ($this->create($groups))(new CreateAccountGroupInput(label: 'C', parentId: null));

        $workspace = \App\Module\Foundation\Domain\WorkspaceScope::fromString(AccountGroupFixture::WORKSPACE);
        self::assertSame(2, $view->sortOrder);
        self::assertSame(0, $groups->find($workspace, $low->id)?->sortOrder);
        self::assertSame(1, $groups->find($workspace, $top->id)?->sortOrder);
    }

    public function testARootKeepsSlotZeroAndTheDefaultPaletteColor(): void
    {
        $view = ($this->create(new InMemoryAccountGroupRepository()))(new CreateAccountGroupInput(label: 'Root', parentId: null));

        self::assertSame('AUTO', $view->colorMode);
        self::assertNull($view->parentResolvedColor);
        self::assertSame($view->defaultColor, $view->resolvedColor);
    }

    public function testAParentModeOnARootIsRefused(): void
    {
        $this->expectException(InvalidAccountGroupInput::class);
        ($this->create(new InMemoryAccountGroupRepository()))(new CreateAccountGroupInput(
            label: 'Root',
            parentId: null,
            colorMode: \App\Module\Accounts\Domain\AccountGroupColorMode::PARENT,
        ));
    }

    public function testASubGroupInheritingItsParentReusesItsEffectiveColor(): void
    {
        $root = AccountGroupFixture::group(color: '#486DF0');
        $view = ($this->create(new InMemoryAccountGroupRepository($root)))(new CreateAccountGroupInput(
            label: 'Child',
            parentId: $root->id,
            colorMode: \App\Module\Accounts\Domain\AccountGroupColorMode::PARENT,
        ));

        self::assertSame('PARENT', $view->colorMode);
        self::assertSame('#486DF0', $view->resolvedColor);
        self::assertNull($view->color);
    }

    private function create(InMemoryAccountGroupRepository $groups): CreateAccountGroup
    {
        $uuids = new SequenceUuidGenerator();

        return new CreateAccountGroup(
            new FixedCallerWorkspace(AccountGroupFixture::WORKSPACE),
            $groups,
            $uuids,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, $uuids),
            new MockClock('2026-09-05 10:00:00'),
            new ReadGroupColors($groups),
        );
    }
}
