<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\AccountRuleOverrideConflict;
use App\Module\Accounts\Application\AccountRuleOverrideInput;
use App\Module\Accounts\Application\DeclaredRuleInput;
use App\Module\Accounts\Application\InvalidAccountRuleOverrideInput;
use App\Module\Accounts\Application\RateBracketInput;
use App\Module\Accounts\Application\RecordAccountRuleOverride;
use App\Module\Accounts\Application\ResolveAccountRuleAuthority;
use App\Module\Accounts\Application\SubmittedAccountRuleOverride;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Tests\Module\Accounts\Application\Double\CollectingAuditEventRepository;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\ImmediateTransactionBoundary;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRuleOverrideRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryProductModelRepository;
use App\Tests\Module\Accounts\Application\Double\SequenceUuidGenerator;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\AccountRuleOverrideFixture;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Recording a local claim against an account: who it is attributed to, what it
 * is allowed to state, and what the account already says that refuses it.
 */
final class RecordAccountRuleOverrideTest extends TestCase
{
    private const string NOW = '2026-09-05 09:00:00';
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    private CollectingAuditEventRepository $trail;

    protected function setUp(): void
    {
        $this->trail = new CollectingAuditEventRepository();
    }

    /**
     * The author is the signed-in caller and never a body field, so a claim
     * can never be attributed to somebody else.
     */
    public function testTheClaimIsAttributedToTheSignedInCallerAndDated(): void
    {
        $override = ($this->record())(AccountFixture::ID, $this->ceilingInput());

        self::assertSame(AccountFixture::ID, $override->accountId);
        self::assertSame('00000000-0000-7000-8000-000000000001', $override->authorId);
        self::assertSame('2026-09-05', $override->recordedAt->format('Y-m-d'));
        self::assertTrue($override->isStanding());
        self::assertSame('30000', $override->value->amount?->value->toString());
        self::assertSame('The branch confirmed a higher ceiling in writing.', $override->reason);
    }

    public function testRecordingLeavesAnAuditEventThatNamesNoAmountOrReason(): void
    {
        $override = ($this->record())(AccountFixture::ID, $this->ceilingInput());

        self::assertCount(1, $this->trail->events);
        $event = $this->trail->events[0];
        self::assertSame('account_rule_override.recorded', $event->eventType);
        self::assertSame($override->id, $event->entityId);

        $serialised = json_encode($event->diff, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('30000', $serialised);
        self::assertStringNotContainsString('branch confirmed', $serialised);
    }

    /**
     * A rate typed onto an account whose product promises none would turn an
     * assumption into a promise. Detaching the product first must not be a way
     * around it either.
     */
    public function testAnAccountThatFollowsNothingAcceptsNoOverrideAtAll(): void
    {
        $record = $this->record(account: AccountFixture::account(productCode: null));

        $this->expectException(InvalidAccountRuleOverrideInput::class);
        $this->expectExceptionMessage('inherits no rule to override');

        $record(AccountFixture::ID, $this->ceilingInput());
    }

    public function testAProductTheCatalogueNoLongerDescribesAcceptsNoNewOverride(): void
    {
        $record = $this->record(catalog: []);

        $this->expectException(InvalidAccountRuleOverrideInput::class);
        $this->expectExceptionMessage('no longer describes');

        $record(AccountFixture::ID, $this->ceilingInput());
    }

    /**
     * A local ceiling is checked against this account and nothing else, so it
     * is recorded in the unit the account is held in.
     */
    public function testAnAmountInAnotherUnitThanTheAccountIsRefused(): void
    {
        $record = $this->record();

        $this->expectException(InvalidAccountRuleOverrideInput::class);
        $this->expectExceptionMessage('EUR');

        $record(AccountFixture::ID, $this->ceilingInput(assetCode: 'USD'));
    }

    public function testARateOnAProductThatPromisesNoneIsRefused(): void
    {
        $record = $this->record(account: AccountFixture::account(productCode: 'FR_CTO'), catalog: [
            new CatalogEntry(CatalogFixture::marketProduct(), RuleSchedule::empty()),
        ]);

        $this->expectException(InvalidAccountRuleOverrideInput::class);
        $this->expectExceptionMessage('earns no stated rate');

        $record(AccountFixture::ID, $this->rateInput());
    }

    public function testAStandingClaimCoveringTheSameDaysRefusesTheNewOne(): void
    {
        $record = $this->record(overrides: [
            AccountRuleOverrideFixture::ceiling('00000000-0000-7000-8000-0000000000c1', '28000', '2026-01-01'),
        ]);

        $this->expectException(AccountRuleOverrideConflict::class);

        $record(AccountFixture::ID, $this->ceilingInput());
    }

    /**
     * Withdrawing frees the dates it claimed, so recording the corrected
     * period over the same days is the normal next step.
     */
    public function testAWithdrawnClaimDoesNotBlockTheCorrectedOne(): void
    {
        $record = $this->record(overrides: [
            AccountRuleOverrideFixture::ceiling(
                '00000000-0000-7000-8000-0000000000c1',
                '28000',
                '2026-01-01',
                withdrawnAt: new \DateTimeImmutable('2026-09-04T10:00:00+00:00'),
            ),
        ]);

        $override = $record(AccountFixture::ID, $this->ceilingInput());

        self::assertSame('30000', $override->value->amount?->value->toString());
    }

    public function testAnArchivedAccountAcceptsNoClaim(): void
    {
        $record = $this->record(account: AccountFixture::account()->archive(
            new \DateTimeImmutable('2026-09-04T10:00:00+00:00'),
        ));

        $this->expectException(AccountArchived::class);

        $record(AccountFixture::ID, $this->ceilingInput());
    }

    /**
     * An account of another workspace answers exactly like one that does not
     * exist, so the refusal never confirms that the identifier is real.
     */
    public function testAnAccountOfAnotherWorkspaceIsAsAbsentAsOneThatNeverExisted(): void
    {
        $record = $this->record(caller: self::OTHER_WORKSPACE);

        $this->expectException(AccountNotFound::class);

        $record(AccountFixture::ID, $this->ceilingInput());
    }

    /**
     * A model-backed account is checked against its model, not against the
     * catalogue: the model is the authority it actually follows.
     */
    public function testAModelBackedAccountIsCheckedAgainstItsModel(): void
    {
        $model = ProductModelFixture::model(schedule: ModelRuleSchedule::empty());
        $record = $this->record(
            account: AccountFixture::account(productCode: null, productModelId: $model->id),
            models: [$model],
        );

        $override = $record(AccountFixture::ID, $this->rateInput());

        self::assertNotNull($override->value->scale);
        self::assertCount(2, $override->value->scale->brackets);
    }

    private function ceilingInput(string $assetCode = 'EUR'): AccountRuleOverrideInput
    {
        return new AccountRuleOverrideInput(
            rule: new DeclaredRuleInput(
                kind: 'DEPOSIT_CEILING',
                amount: '30000',
                amountAssetCode: $assetCode,
                text: null,
                rateApplication: null,
                brackets: [],
                validFrom: '2026-01-01',
                validTo: null,
            ),
            reason: 'The branch confirmed a higher ceiling in writing.',
        );
    }

    private function rateInput(): AccountRuleOverrideInput
    {
        return new AccountRuleOverrideInput(
            rule: new DeclaredRuleInput(
                kind: 'ANNUAL_RATE',
                amount: null,
                amountAssetCode: null,
                text: null,
                rateApplication: 'MARGINAL',
                brackets: [
                    new RateBracketInput('0', '10000', '4'),
                    new RateBracketInput('10000', null, '2'),
                ],
                validFrom: '2026-01-01',
                validTo: null,
            ),
            reason: 'Promotional rate confirmed by the account statement.',
        );
    }

    /**
     * @param list<CatalogEntry>|null        $catalog
     * @param list<ProductModel>|null        $models
     * @param list<AccountRuleOverride>|null $overrides
     */
    private function record(
        ?Account $account = null,
        ?array $catalog = null,
        ?array $models = null,
        ?array $overrides = null,
        string $caller = AccountFixture::WORKSPACE,
    ): RecordAccountRuleOverride {
        $productCatalog = new InMemoryProductCatalog($catalog ?? [
            new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
                CatalogFixture::ceiling('22950.00', '2025-04-25'),
            ])),
        ]);
        $productModels = new InMemoryProductModelRepository(...($models ?? []));

        return new RecordAccountRuleOverride(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository($account ?? AccountFixture::account()),
            new InMemoryAccountRuleOverrideRepository(...($overrides ?? [])),
            new ResolveAccountRuleAuthority($productCatalog, $productModels),
            new SubmittedAccountRuleOverride(
                new SequenceUuidGenerator(),
                InMemoryAssetCatalog::withCodes('EUR', 'USD'),
            ),
            new ImmediateTransactionBoundary(),
            new RecordAuditEvent($this->trail, new SequenceUuidGenerator()),
            new MockClock(self::NOW, 'UTC'),
        );
    }
}
