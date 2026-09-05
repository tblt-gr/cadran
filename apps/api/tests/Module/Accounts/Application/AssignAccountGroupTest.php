<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountProduct;
use App\Module\Accounts\Application\AccountProductModel;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Application\UpdateAccount;
use App\Module\Accounts\Application\UpdateAccountInput;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountGroupRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryProductModelRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountGroupFixture;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AssignAccountGroupTest extends TestCase
{
    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    public function testItAssignsAPrimaryGroupAndKeepsTagsOutOfTheExclusiveLink(): void
    {
        $account = AccountFixture::account();
        $primary = AccountGroupFixture::group();
        $tag = AccountGroupFixture::group(
            id: AccountGroupFixture::CHILD_ID,
            label: 'Projets',
        );

        $view = ($this->update(new InMemoryAccountRepository($account), new InMemoryAccountGroupRepository($primary, $tag)))(
            $account->id,
            $this->input(primaryGroupId: $primary->id, tagGroupIds: [$tag->id]),
        );

        self::assertSame($primary->id, $view->primaryGroupId);
        self::assertSame([$tag->id], $view->tagGroupIds);
        self::assertNotNull($view->share);
        self::assertSame('MISSING_VALUATION', $view->share->reason);
        self::assertStringNotContainsString($primary->label, json_encode($this->trail->events[0]->diff, JSON_THROW_ON_ERROR));
    }

    public function testAnUnknownGroupIsRefused(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('group');

        ($this->update(new InMemoryAccountRepository(AccountFixture::account()), new InMemoryAccountGroupRepository()))(
            AccountFixture::ID,
            $this->input(primaryGroupId: AccountGroupFixture::ID),
        );
    }

    public function testAGroupFromAnotherWorkspaceIsRefused(): void
    {
        $foreign = AccountGroupFixture::group(
            workspace: AccountGroupFixture::OTHER_WORKSPACE,
            label: 'Foreign',
        );

        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('group');

        ($this->update(
            new InMemoryAccountRepository(AccountFixture::account()),
            new InMemoryAccountGroupRepository($foreign),
        ))(AccountFixture::ID, $this->input(primaryGroupId: $foreign->id));
    }

    /**
     * @param list<string> $tagGroupIds
     */
    private function input(?string $primaryGroupId, array $tagGroupIds = []): UpdateAccountInput
    {
        $account = AccountFixture::account();

        return new UpdateAccountInput(
            label: $account->label,
            kind: $account->kind->value,
            productCode: $account->productCode?->toString(),
            productModelId: $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier?->toString(),
            valuationMode: $account->valuationMode->value,
            liquidityLevel: $account->liquidityLevel->value,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: $account->openedOn->format('Y-m-d'),
            closedOn: null,
            version: $account->version,
            primaryGroupId: $primaryGroupId,
            tagGroupIds: $tagGroupIds,
        );
    }

    private function update(InMemoryAccountRepository $accounts, InMemoryAccountGroupRepository $groups): UpdateAccount
    {
        return new UpdateAccount(
            new FixedCallerWorkspace(AccountFixture::WORKSPACE),
            $accounts,
            new AccountProduct(new InMemoryProductCatalog([])),
            new AccountProductModel(new InMemoryProductModelRepository()),
            $groups,
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock('2026-09-05 10:00:00'),
        );
    }
}
