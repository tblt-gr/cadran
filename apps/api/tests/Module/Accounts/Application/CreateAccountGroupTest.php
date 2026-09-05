<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\CreateAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroupInput;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
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
            sortOrder: 0,
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
            sortOrder: 0,
        ));
    }

    public function testCreatingLeavesAnAuditEventThatNamesNoLabel(): void
    {
        ($this->create(new InMemoryAccountGroupRepository()))(new CreateAccountGroupInput(
            label: 'Épargne',
            parentId: null,
            sortOrder: 0,
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
            sortOrder: 1,
        ));
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
        );
    }
}
