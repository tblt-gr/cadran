<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Application\ReadAccountRules;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRulesOrigin;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Module\Catalog\Domain\VerificationState;
use App\Tests\Module\Accounts\Application\Double\FixedCallerWorkspace;
use App\Tests\Module\Accounts\Application\Double\InMemoryAccountRepository;
use App\Tests\Module\Accounts\Application\Double\InMemoryProductModelRepository;
use App\Tests\Module\Accounts\Domain\AccountFixture;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReadAccountRulesTest extends TestCase
{
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    public function testTheRulesAreResolvedOnTheBusinessDateAskedFor(): void
    {
        $rules = ($this->readRules())(AccountFixture::ID, '2026-03-15');

        self::assertSame('2026-03-15', $rules->asOf->format('Y-m-d'));
        self::assertSame('2.4', $rules->rates[0]->scale->brackets[0]->percentage->toString());
    }

    /**
     * No business date means today, and today is the clock's day. Reading the
     * rules must never fall back to whichever period happens to be last.
     */
    public function testWithoutABusinessDateTheClockDayIsUsed(): void
    {
        $rules = ($this->readRules())(AccountFixture::ID, null);

        self::assertSame('2026-09-02', $rules->asOf->format('Y-m-d'));
        self::assertSame('1.7', $rules->rates[0]->scale->brackets[0]->percentage->toString());
    }

    /**
     * The business date selects the period; the clock only grades how fresh
     * the verification looks. The two must not be conflated.
     */
    public function testTheClockGradesVerificationWhileTheBusinessDateSelectsThePeriod(): void
    {
        $rules = $this->readRules(now: '2030-01-01 00:00:00')(AccountFixture::ID, '2026-09-02');

        self::assertSame('22950.00', $rules->ceilings[0]->amount->value->toString());
        self::assertSame(VerificationState::STALE, $rules->ceilings[0]->verification);
    }

    #[DataProvider('malformedDates')]
    public function testAMalformedBusinessDateIsRefusedBeforeAnythingIsRead(string $asOf): void
    {
        $this->expectException(InvalidAccountInput::class);

        ($this->readRules())(AccountFixture::ID, $asOf);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDates(): iterable
    {
        yield 'french order' => ['15/03/2026'];
        yield 'overflowing day' => ['2026-02-31'];
        yield 'timestamp' => ['2026-03-15T00:00:00Z'];
        yield 'before the earliest supported day' => ['1899-12-31'];
        yield 'beyond the latest supported day' => ['2101-01-01'];
        yield 'sql fragment' => ["2026-03-15' OR '1'='1"];
    }

    /**
     * An account of another workspace answers exactly like one that does not
     * exist, so the refusal never confirms that the identifier is real.
     */
    public function testAnAccountOfAnotherWorkspaceIsAsAbsentAsOneThatNeverExisted(): void
    {
        $readRules = $this->readRules(caller: self::OTHER_WORKSPACE);

        $refusals = [];
        foreach ([AccountFixture::ID, '00000000-0000-7000-8000-0000000000ff'] as $candidate) {
            try {
                $readRules($candidate, '2026-09-02');
                self::fail(sprintf('%s should be refused.', $candidate));
            } catch (AccountNotFound $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        self::assertSame([$refusals[0], $refusals[0]], $refusals);
    }

    public function testAnAccountDescribedByHandNeedsNoCatalogueLookup(): void
    {
        $readRules = $this->readRules(account: AccountFixture::account(productCode: null), catalog: []);

        $rules = $readRules(AccountFixture::ID, '2026-09-02');

        self::assertSame(AccountRulesOrigin::NO_PRODUCT, $rules->origin);
        self::assertSame('2026-09-02', $rules->asOf->format('Y-m-d'));
    }

    /**
     * The catalogue hides a withdrawn product while the account keeps the
     * reference it was created with. That must answer, not fail.
     */
    public function testAnAccountWhoseProductLeftTheCatalogueStillAnswers(): void
    {
        $readRules = $this->readRules(catalog: []);

        $rules = $readRules(AccountFixture::ID, '2026-09-02');

        self::assertSame(AccountRulesOrigin::PRODUCT_WITHDRAWN, $rules->origin);
        self::assertSame('FR_LIVRET_A', $rules->productCode?->toString());
        self::assertSame([], $rules->ceilings);
    }

    public function testAModelBackedAccountResolvesItsRulesFromTheWorkspaceModel(): void
    {
        $model = ProductModelFixture::model(schedule: new ModelRuleSchedule([
            ProductModelFixture::ceiling('00000000-0000-7000-8000-0000000000b1', '22950.00', '2025-04-25'),
            ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b2', '2026-08-01'),
        ]));
        $readRules = $this->readRules(
            account: AccountFixture::account(productCode: null, productModelId: $model->id),
            models: [$model],
        );

        $rules = $readRules(AccountFixture::ID, '2026-09-02');

        self::assertSame(AccountRulesOrigin::WORKSPACE_MODEL, $rules->origin);
        self::assertSame($model->id, $rules->productModelId);
        self::assertNull($rules->productCode);
        self::assertCount(1, $rules->ceilings);
        // A model rule carries no publication: grading its freshness or
        // naming a source would fabricate a provenance it never had.
        self::assertNull($rules->ceilings[0]->verification);
        self::assertNull($rules->ceilings[0]->source);
    }

    /**
     * Archiving a model stops new accounts from starting on it, but it must
     * never change what an account already backed by it resolves.
     */
    public function testAnArchivedModelStillResolvesTheAccountItAlreadyBacks(): void
    {
        $model = ProductModelFixture::model(
            schedule: new ModelRuleSchedule([
                ProductModelFixture::rate('00000000-0000-7000-8000-0000000000b1', '2026-01-01'),
            ]),
            archivedAt: new \DateTimeImmutable('2026-09-03T09:00:00+00:00'),
        );
        $readRules = $this->readRules(
            account: AccountFixture::account(productCode: null, productModelId: $model->id),
            models: [$model],
        );

        $rules = $readRules(AccountFixture::ID, '2026-09-02');

        self::assertSame(AccountRulesOrigin::WORKSPACE_MODEL, $rules->origin);
        self::assertCount(1, $rules->rates);
    }

    /**
     * @param list<CatalogEntry>|null $catalog
     * @param list<ProductModel>|null $models
     */
    private function readRules(
        string $now = '2026-09-02 08:30:00',
        string $caller = AccountFixture::WORKSPACE,
        ?Account $account = null,
        ?array $catalog = null,
        ?array $models = null,
    ): ReadAccountRules {
        return new ReadAccountRules(
            new FixedCallerWorkspace($caller),
            new InMemoryAccountRepository($account ?? AccountFixture::account()),
            new InMemoryProductCatalog($catalog ?? [$this->livretA()]),
            new InMemoryProductModelRepository(...($models ?? [])),
            new MockClock($now, 'UTC'),
        );
    }

    private function livretA(): CatalogEntry
    {
        return new CatalogEntry(CatalogFixture::product(), new RuleSchedule([
            CatalogFixture::ceiling('22950.00', '2025-04-25'),
            CatalogFixture::rate('2.4', '2026-02-01', '2026-07-31'),
            CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31'),
        ]));
    }
}
