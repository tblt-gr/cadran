<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\MaskedIdentifier;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Accounts\Infrastructure\Persistence\DbalProductModelRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountPersistenceTest extends KernelTestCase
{
    private const string OWN_ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    private const string OTHER_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    private const string EXCLUDED_ACCOUNT = '00000000-0000-7000-8000-0000000000d3';
    private const string ARCHIVED_ACCOUNT = '00000000-0000-7000-8000-0000000000d4';
    private const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-0000000000d5';
    private const string OWN_MODEL = ProductModelFixture::ID;
    private const string OTHER_MODEL = '00000000-0000-7000-8000-0000000000e2';

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAccountRepository $repository;
    private DbalProductModelRepository $models;
    private bool $databaseReady = false;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->repository = new DbalAccountRepository($connection);
        $this->models = new DbalProductModelRepository($connection);
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
        // One model per workspace, so the FK and cross-workspace scenarios
        // below reference a row that actually exists.
        $this->models->add(ProductModelFixture::model(id: self::OWN_MODEL, workspace: WorkspaceFixture::OWN_WORKSPACE));
        $this->models->add(ProductModelFixture::model(id: self::OTHER_MODEL, workspace: WorkspaceFixture::OTHER_WORKSPACE));
    }

    protected function tearDown(): void
    {
        if ($this->databaseReady) {
            $this->fixture->reset();
        }
        parent::tearDown();
    }

    public function testAccountsAreOnlyReadableInsideTheirWorkspace(): void
    {
        $this->repository->add($this->account(self::OWN_ACCOUNT, WorkspaceFixture::own()));
        $this->repository->add($this->account(self::OTHER_ACCOUNT, WorkspaceFixture::other(), label: 'Compte voisin'));

        self::assertSame(
            [self::OWN_ACCOUNT],
            array_column($this->repository->list(WorkspaceFixture::own(), false, true, 100, 0), 'id'),
        );
        self::assertSame(1, $this->repository->count(WorkspaceFixture::own(), false, true));
        self::assertNull($this->repository->findForUpdate(WorkspaceFixture::own(), self::OTHER_ACCOUNT));
        self::assertNotNull($this->repository->findForUpdate(WorkspaceFixture::other(), self::OTHER_ACCOUNT));
    }

    public function testAnActiveLabelIsUniqueIgnoringCaseAndFreedByArchiving(): void
    {
        $account = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->repository->add($account);
        self::assertTrue($this->repository->hasActiveLabel(WorkspaceFixture::own(), 'livret a banque x'));

        try {
            $this->repository->add($this->account(self::OTHER_ACCOUNT, WorkspaceFixture::own(), label: 'livret a banque x'));
            self::fail('An active namesake should conflict.');
        } catch (AccountConflict) {
            // The unique index is the authority; the conflict travels as an application error.
        }

        self::assertTrue($this->repository->update($account->archive(new \DateTimeImmutable()), 1));
        self::assertFalse($this->repository->hasActiveLabel(WorkspaceFixture::own(), 'Livret A Banque X'));
        $this->repository->add($this->account(self::OTHER_ACCOUNT, WorkspaceFixture::own()));
        self::assertSame(2, $this->repository->count(WorkspaceFixture::own(), true, true));
    }

    public function testClosedAndArchivedAccountsLeaveTheDefaultListing(): void
    {
        $open = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->repository->add($open);
        $closed = $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::own(), label: 'Compte clos', closedOn: '2026-02-01');
        $this->repository->add($closed);

        self::assertSame([self::OWN_ACCOUNT], array_column($this->repository->list(WorkspaceFixture::own(), false, false, 100, 0), 'id'));
        self::assertSame(2, $this->repository->count(WorkspaceFixture::own(), false, true));
        self::assertSame(1, $this->repository->count(WorkspaceFixture::own(), false, false));
    }

    public function testTheNetWorthListingKeepsClosedAccountsAndDropsArchivedAndExcludedOnes(): void
    {
        $this->repository->add($this->account(self::OWN_ACCOUNT, WorkspaceFixture::own()));
        $closed = $this->account(self::OTHER_ACCOUNT, WorkspaceFixture::own(), label: 'Compte clos', closedOn: '2026-02-01');
        $this->repository->add($closed);
        $excluded = $this->account(self::EXCLUDED_ACCOUNT, WorkspaceFixture::own(), label: 'Compte joint tiers')
            ->reconfigure(
                label: 'Compte joint tiers',
                kind: AccountKind::SAVINGS,
                productCode: ProductCode::fromString('FR_LIVRET_A'),
                productModelId: null,
                institution: 'Banque X',
                maskedIdentifier: MaskedIdentifier::fromString('4821'),
                valuationMode: AccountValuationMode::TRANSACTIONS,
                liquidityLevel: LiquidityLevel::IMMEDIATE,
                includeInNetWorth: false,
                includeInEmergencyFund: false,
                openedOn: new \DateTimeImmutable('2026-01-10', new \DateTimeZone('UTC')),
                closedOn: null,
                updatedAt: new \DateTimeImmutable('2026-09-01T12:00:00+00:00'),
            );
        $this->repository->add($excluded);
        $this->repository->add($this->account(self::ARCHIVED_ACCOUNT, WorkspaceFixture::own(), label: 'Compte archivé')
            ->archive(new \DateTimeImmutable('2026-09-02T12:00:00+00:00')));
        $this->repository->add($this->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::other()));

        // Closure is dated, so a closed account still has to reach the
        // calculation, which decides day by day whether it counted.
        self::assertSame(
            [self::OTHER_ACCOUNT, self::OWN_ACCOUNT],
            array_column($this->repository->listForNetWorth(WorkspaceFixture::own(), 100), 'id'),
        );
    }

    public function testAMonthListingDropsAccountsClosedOrArchivedBeforeItAndKeepsThoseOpenDuringIt(): void
    {
        $id = static fn (int $n): string => sprintf('00000000-0000-7000-8000-00000000f%03d', $n);
        $this->repository->add($this->account($id(1), WorkspaceFixture::own(), label: 'Actif'));
        $this->repository->add($this->account($id(2), WorkspaceFixture::own(), label: 'Clos veille', closedOn: '2026-04-30'));
        $this->repository->add($this->account($id(3), WorkspaceFixture::own(), label: 'Clos le 1er', closedOn: '2026-05-01'));
        $this->repository->add($this->account($id(4), WorkspaceFixture::own(), label: 'Clos dans le mois', closedOn: '2026-05-15'));
        $this->repository->add($this->account($id(5), WorkspaceFixture::own(), label: 'Clos apres', closedOn: '2026-06-10'));
        $this->repository->add($this->account($id(6), WorkspaceFixture::own(), label: 'Archive avant')
            ->archive(new \DateTimeImmutable('2026-04-30T12:00:00+00:00')));
        $this->repository->add($this->account($id(7), WorkspaceFixture::own(), label: 'Archive dans le mois')
            ->archive(new \DateTimeImmutable('2026-05-15T12:00:00+00:00')));
        $this->repository->add($this->account($id(8), WorkspaceFixture::other(), label: 'Autre espace'));

        $listed = $this->repository->listOpenDuring(
            WorkspaceFixture::own(),
            new \DateTimeImmutable('2026-05-01', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-05-31', new \DateTimeZone('UTC')),
            new \DateTimeZone('Europe/Paris'),
            100,
        );

        $ids = array_map(static fn (Account $account): string => $account->id, $listed);
        sort($ids);
        self::assertSame([$id(1), $id(3), $id(4), $id(5), $id(7)], $ids);
    }

    public function testOptimisticVersioningRejectsAStaleUpdate(): void
    {
        $account = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->repository->add($account);
        $renamed = $account->reconfigure(
            label: 'Livret A Banque Y',
            kind: $account->kind,
            productCode: $account->productCode,
            productModelId: $account->productModelId,
            institution: $account->institution,
            maskedIdentifier: $account->maskedIdentifier,
            valuationMode: $account->valuationMode,
            liquidityLevel: $account->liquidityLevel,
            includeInNetWorth: $account->includeInNetWorth,
            includeInEmergencyFund: $account->includeInEmergencyFund,
            openedOn: $account->openedOn,
            closedOn: $account->closedOn,
            updatedAt: new \DateTimeImmutable(),
        );

        self::assertTrue($this->repository->update($renamed, 1));
        self::assertFalse($this->repository->update($renamed, 1));
        self::assertSame('Livret A Banque Y', $this->repository->findForUpdate(WorkspaceFixture::own(), self::OWN_ACCOUNT)?->label);
    }

    public function testAStoredAccountKeepsItsDenominationAndCalendarDays(): void
    {
        $this->repository->add($this->account(self::OWN_ACCOUNT, WorkspaceFixture::own(), closedOn: '2026-02-01'));

        $stored = $this->repository->findForUpdate(WorkspaceFixture::own(), self::OWN_ACCOUNT);
        self::assertNotNull($stored);
        self::assertSame('EUR', $stored->assetCode->toString());
        self::assertSame('2026-01-10', $stored->openedOn->format('Y-m-d'));
        self::assertSame('2026-02-01', $stored->closedOn?->format('Y-m-d'));
        self::assertSame('4821', (string) $stored->maskedIdentifier);
        self::assertSame('FR_LIVRET_A', $stored->productCode?->toString());
        self::assertSame('Banque X', $stored->institution);
    }

    public function testAnAccountDescribedByHandStoresNoProductReference(): void
    {
        $this->repository->add($this->account(self::OWN_ACCOUNT, WorkspaceFixture::own(), productCode: null, institution: null));

        $stored = $this->repository->findForUpdate(WorkspaceFixture::own(), self::OWN_ACCOUNT);
        self::assertNotNull($stored);
        self::assertNull($stored->productCode);
        self::assertNull($stored->institution);
    }

    public function testAModelBackedAccountRoundTripsItsReferenceInsteadOfACatalogueProduct(): void
    {
        $this->repository->add($this->account(
            self::OWN_ACCOUNT,
            WorkspaceFixture::own(),
            productCode: null,
            productModelId: self::OWN_MODEL,
        ));

        $stored = $this->repository->findForUpdate(WorkspaceFixture::own(), self::OWN_ACCOUNT);
        self::assertNotNull($stored);
        self::assertSame(self::OWN_MODEL, $stored->productModelId);
        self::assertNull($stored->productCode);
    }

    /**
     * The application is not the only possible writer, so the aggregate rules
     * are repeated as constraints. A migration, a fixture or a future import
     * must meet the same refusals.
     *
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('rejectedRows')]
    public function testTheDatabaseRepeatsTheAggregateInvariants(array $overrides, string $expected): void
    {
        $this->expectException(DbalException::class);
        $this->expectExceptionMessageMatches($expected);

        $this->connection->insert('account_financial_accounts', [
            'id' => self::OWN_ACCOUNT,
            'workspace_id' => WorkspaceFixture::OWN_WORKSPACE,
            'label' => 'Livret A Banque X',
            'asset_code' => 'EUR',
            'kind' => 'SAVINGS',
            'product_code' => 'FR_LIVRET_A',
            'institution' => 'Banque X',
            'masked_identifier' => '4821',
            'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => true,
            'include_in_emergency_fund' => false,
            'opened_on' => '2026-01-10',
            'closed_on' => null,
            'version' => 1,
            'created_at' => '2026-09-01 12:00:00+00',
            'updated_at' => '2026-09-01 12:00:00+00',
            ...$overrides,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function rejectedRows(): iterable
    {
        // The column width refuses a full identifier before the format check
        // ever sees it, which is the point: the tail is all that fits.
        yield 'full banking identifier' => [
            ['masked_identifier' => 'FR7630006000011234567890189'],
            '/value too long for type character varying\(8\)/',
        ];
        yield 'unmasked separator' => [
            ['masked_identifier' => '48 21'],
            '/masked_identifier_valid/',
        ];
        yield 'portfolio valuation without positions' => [
            ['valuation_mode' => 'PORTFOLIO', 'kind' => 'CURRENT'],
            '/portfolio_valuation_holds_positions/',
        ];
        yield 'emergency fund outside net worth' => [
            ['include_in_net_worth' => false, 'include_in_emergency_fund' => true],
            '/emergency_fund_counted/',
        ];
        yield 'closing before opening' => [
            ['closed_on' => '2026-01-09'],
            '/closed_after_opening/',
        ];
        yield 'opening in the future' => [
            ['opened_on' => '2026-09-02', 'created_at' => '2026-09-01 12:00:00+00', 'updated_at' => '2026-09-01 12:00:00+00'],
            '/opened_on_bounded/',
        ];
        yield 'unknown denomination' => [
            ['asset_code' => 'ZZZ'],
            '/asset_fk/',
        ];
        // The catalogue carries no workspace, so an unknown code and a code
        // belonging elsewhere are one and the same refusal here.
        yield 'unknown product' => [
            ['product_code' => 'FR_UNKNOWN_PRODUCT'],
            '/product_fk/',
        ];
        // The reference is (code, kind), so a PEA cannot be filed as a passbook
        // by any writer: a later ceiling check would then read the deposited
        // balance instead of the cumulative contributions.
        yield 'product filed under another kind' => [
            ['product_code' => 'FR_PEA', 'kind' => 'SAVINGS'],
            '/product_fk/',
        ];
        yield 'blank institution' => [
            ['institution' => '   '],
            '/institution_present/',
        ];
        yield 'unknown model' => [
            ['product_code' => null, 'product_model_id' => '00000000-0000-7000-8000-0000000000ff'],
            '/product_model_fk/',
        ];
        // The reference is (id, workspace_id), so a model of another workspace
        // cannot back this account by any writer: the FK sees an id that
        // exists, paired with a workspace it does not belong to.
        yield 'model of another workspace' => [
            ['product_code' => null, 'product_model_id' => self::OTHER_MODEL],
            '/product_model_fk/',
        ];
        // The reference is (id, workspace_id, family), so a savings model
        // cannot be filed as a current account by any writer: a later ceiling
        // would then be read against the wrong kind.
        yield 'model filed under another kind' => [
            ['product_code' => null, 'product_model_id' => self::OWN_MODEL, 'kind' => 'CURRENT'],
            '/product_model_fk/',
        ];
        yield 'both a catalogue product and a model' => [
            ['product_model_id' => self::OWN_MODEL],
            '/single_origin/',
        ];
    }

    private function account(
        string $id,
        WorkspaceScope $workspace,
        string $label = 'Livret A Banque X',
        ?string $closedOn = null,
        ?string $productCode = 'FR_LIVRET_A',
        ?string $productModelId = null,
        ?string $institution = 'Banque X',
    ): Account {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Account(
            id: $id,
            workspace: $workspace,
            label: $label,
            assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::SAVINGS,
            productCode: null === $productCode ? null : ProductCode::fromString($productCode),
            productModelId: $productModelId,
            institution: $institution,
            maskedIdentifier: MaskedIdentifier::fromString('4821'),
            valuationMode: AccountValuationMode::TRANSACTIONS,
            liquidityLevel: LiquidityLevel::IMMEDIATE,
            includeInNetWorth: true,
            includeInEmergencyFund: false,
            openedOn: new \DateTimeImmutable('2026-01-10', $utc),
            closedOn: null === $closedOn ? null : new \DateTimeImmutable($closedOn, $utc),
            version: 1,
            createdAt: $now,
            updatedAt: $now,
        );
    }
}
