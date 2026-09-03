<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Infrastructure\Persistence;

use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\LiquidityLevel;
use App\Module\Accounts\Domain\MaskedIdentifier;
use App\Module\Accounts\Infrastructure\Persistence\DbalAccountRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\WorkspaceScope;
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

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalAccountRepository $repository;
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
        $this->databaseReady = true;
        $this->fixture->reset();
        $this->fixture->seed();
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

    public function testOptimisticVersioningRejectsAStaleUpdate(): void
    {
        $account = $this->account(self::OWN_ACCOUNT, WorkspaceFixture::own());
        $this->repository->add($account);
        $renamed = $account->reconfigure(
            label: 'Livret A Banque Y',
            kind: $account->kind,
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
    }

    private function account(
        string $id,
        WorkspaceScope $workspace,
        string $label = 'Livret A Banque X',
        ?string $closedOn = null,
    ): Account {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2026-09-01T12:00:00+00:00');

        return new Account(
            id: $id,
            workspace: $workspace,
            label: $label,
            assetCode: AssetCode::fromString('EUR'),
            kind: AccountKind::SAVINGS,
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
