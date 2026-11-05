<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Infrastructure\Persistence\DbalTransactionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The hand-computed filter-facet data set, in EUR over January 2026. The workspace
 * `OWN_WORKSPACE` holds two accounts, a category tree (Food with Groceries and Restaurants
 * below it, and Transport) and eight transactions; the other workspace holds an account, a
 * category and three transactions that touch every facet, so any leak inflates a count.
 *
 * | tx | account | amount   | nature  | source | state    | splits (category, axes)           |
 * |----|---------|----------|---------|--------|----------|-----------------------------------|
 * | t1 | A1      | -50.00   | EXPENSE | MANUAL | BOOKED   | Groceries -50 [ESSENTIAL]         |
 * | t2 | A1      | -30.00   | EXPENSE | IMPORT | BOOKED   | Groceries -20 [ESSENTIAL] + Restaurants -10 [VARIABLE] |
 * | t3 | A1      | -15.00   | EXPENSE | IMPORT | PENDING  | Transport -15 [VARIABLE]          |
 * | t4 | A1      | +1000.00 | INCOME  | IMPORT | BOOKED   | none                              |
 * | t5 | A2      | -5.00    | EXPENSE | MANUAL | BOOKED   | Food -5 [ESSENTIAL]               |
 * | t6 | A2      | +20.00   | REFUND  | MANUAL | BOOKED   | Groceries +20 [ESSENTIAL]         |
 * | t7 | A1      | -9.00    | EXPENSE | MANUAL | VOIDED   | Transport -9 []                   |
 * | t8 | A2      | -7.00    | FEE     | IMPORT | REJECTED | none                              |
 */
final readonly class TransactionFacetFixture
{
    public const string CURRENT = '00000000-0000-7000-8000-00000000a001';
    public const string SAVINGS = '00000000-0000-7000-8000-00000000a002';
    public const string FOREIGN_ACCOUNT = '00000000-0000-7000-8000-00000000a0b1';
    public const string FOOD = '00000000-0000-7000-8000-00000000c001';
    public const string GROCERIES = '00000000-0000-7000-8000-00000000c002';
    public const string RESTAURANTS = '00000000-0000-7000-8000-00000000c003';
    public const string TRANSPORT = '00000000-0000-7000-8000-00000000c004';
    public const string FOREIGN_CATEGORY = '00000000-0000-7000-8000-00000000c0b1';
    public const string T1 = '00000000-0000-7000-8000-00000000e001';
    public const string T2 = '00000000-0000-7000-8000-00000000e002';
    public const string T3 = '00000000-0000-7000-8000-00000000e003';
    public const string T4 = '00000000-0000-7000-8000-00000000e004';
    public const string T5 = '00000000-0000-7000-8000-00000000e005';
    public const string T6 = '00000000-0000-7000-8000-00000000e006';
    public const string T7 = '00000000-0000-7000-8000-00000000e007';
    public const string T8 = '00000000-0000-7000-8000-00000000e008';

    private const string STAMP = '2026-01-31 12:00:00.000000+00';

    private DbalTransactionRepository $transactions;

    public function __construct(private Connection $connection)
    {
        $this->transactions = new DbalTransactionRepository($connection);
    }

    /** Seeds both workspaces; the caller has already created them with {@see WorkspaceFixture::seed()}. */
    public function seed(): void
    {
        $this->account(self::CURRENT, WorkspaceFixture::OWN_WORKSPACE, 'Compte courant');
        $this->account(self::SAVINGS, WorkspaceFixture::OWN_WORKSPACE, 'Livret A');
        $this->account(self::FOREIGN_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Compte voisin');
        $this->category(self::FOOD, WorkspaceFixture::OWN_WORKSPACE, 'Food', null, 1);
        $this->category(self::GROCERIES, WorkspaceFixture::OWN_WORKSPACE, 'Groceries', self::FOOD, 2);
        $this->category(self::RESTAURANTS, WorkspaceFixture::OWN_WORKSPACE, 'Restaurants', self::FOOD, 2);
        $this->category(self::TRANSPORT, WorkspaceFixture::OWN_WORKSPACE, 'Transport', null, 1);
        $this->category(self::FOREIGN_CATEGORY, WorkspaceFixture::OTHER_WORKSPACE, 'Prive voisin', null, 1);

        $own = WorkspaceFixture::OWN_WORKSPACE;
        $this->transaction($own, self::T1, self::CURRENT, '-50.00', TransactionNature::EXPENSE, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-01-05', [
            [self::GROCERIES, '-50.00', [AnalyticAxis::ESSENTIAL]],
        ]);
        $this->transaction($own, self::T2, self::CURRENT, '-30.00', TransactionNature::EXPENSE, TransactionSource::IMPORT, TransactionState::BOOKED, '2026-01-10', [
            [self::GROCERIES, '-20.00', [AnalyticAxis::ESSENTIAL]],
            [self::RESTAURANTS, '-10.00', [AnalyticAxis::VARIABLE]],
        ]);
        $this->transaction($own, self::T3, self::CURRENT, '-15.00', TransactionNature::EXPENSE, TransactionSource::IMPORT, TransactionState::PENDING, '2026-01-15', [
            [self::TRANSPORT, '-15.00', [AnalyticAxis::VARIABLE]],
        ]);
        $this->transaction($own, self::T4, self::CURRENT, '1000.00', TransactionNature::INCOME, TransactionSource::IMPORT, TransactionState::BOOKED, '2026-01-20', []);
        $this->transaction($own, self::T5, self::SAVINGS, '-5.00', TransactionNature::EXPENSE, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-01-25', [
            [self::FOOD, '-5.00', [AnalyticAxis::ESSENTIAL]],
        ]);
        $this->transaction($own, self::T6, self::SAVINGS, '20.00', TransactionNature::REFUND, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-01-28', [
            [self::GROCERIES, '20.00', [AnalyticAxis::ESSENTIAL]],
        ]);
        $this->transaction($own, self::T7, self::CURRENT, '-9.00', TransactionNature::EXPENSE, TransactionSource::MANUAL, TransactionState::VOIDED, '2026-01-12', [
            [self::TRANSPORT, '-9.00', []],
        ]);
        $this->transaction($own, self::T8, self::SAVINGS, '-7.00', TransactionNature::FEE, TransactionSource::IMPORT, TransactionState::REJECTED, '2026-01-18', []);

        $other = WorkspaceFixture::OTHER_WORKSPACE;
        $this->transaction($other, '00000000-0000-7000-8000-00000000e0b1', self::FOREIGN_ACCOUNT, '-11.00', TransactionNature::EXPENSE, TransactionSource::MANUAL, TransactionState::BOOKED, '2026-01-06', [
            [self::FOREIGN_CATEGORY, '-11.00', [AnalyticAxis::ESSENTIAL, AnalyticAxis::VARIABLE]],
        ]);
        $this->transaction($other, '00000000-0000-7000-8000-00000000e0b2', self::FOREIGN_ACCOUNT, '12.00', TransactionNature::INCOME, TransactionSource::IMPORT, TransactionState::BOOKED, '2026-01-07', []);
        $this->transaction($other, '00000000-0000-7000-8000-00000000e0b3', self::FOREIGN_ACCOUNT, '-13.00', TransactionNature::EXPENSE, TransactionSource::IMPORT, TransactionState::PENDING, '2026-01-08', [
            [self::FOREIGN_CATEGORY, '-13.00', [AnalyticAxis::ESSENTIAL]],
        ]);
    }

    /**
     * @param list<array{string, string, list<AnalyticAxis>}> $splits category, amount, axes
     */
    public function transaction(
        string $workspaceId,
        string $id,
        string $accountId,
        string $amount,
        TransactionNature $nature,
        TransactionSource $source,
        TransactionState $state,
        string $bookedOn,
        array $splits,
        ?string $label = null,
    ): void {
        $workspace = WorkspaceScope::fromString($workspaceId);
        $stamp = new \DateTimeImmutable(self::STAMP);
        $total = new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR'));

        // The deferred trigger that checks the split sum fires at commit, so a transaction and all
        // of its splits must be written inside one database transaction.
        $this->connection->transactional(fn () => $this->transactions->add(new Transaction(
            id: $id, workspace: $workspace, accountId: $accountId, amount: $total,
            originalAmount: null, exchangeRate: null, state: $state, nature: $nature, source: $source, sourceRef: null,
            bookedOn: new \DateTimeImmutable($bookedOn), valueOn: null, authorizedOn: null,
            rawLabel: $label ?? 'CB '.substr($id, -4), counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null,
            splits: array_map(
                static fn (array $split, int $position): TransactionSplit => new TransactionSplit(
                    '10000000-0000-7000-8000-'.substr($id, -8).sprintf('%04x', $position), $workspace, $id, $split[0],
                    new AssetAmount(DecimalValue::fromString($split[1]), AssetCode::fromString('EUR')), $split[2], null, $stamp, $position,
                ),
                $splits,
                array_keys($splits),
            ),
            version: 1, createdAt: $stamp, updatedAt: $stamp,
            voidedAt: TransactionState::VOIDED === $state ? $stamp : null,
            lastEditorId: WorkspaceFixture::OWNER_ID,
        )));
    }

    public function account(string $id, string $workspaceId, string $label): void
    {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspaceId, 'label' => $label, 'asset_code' => 'EUR',
            'kind' => 'CURRENT', 'masked_identifier' => null, 'valuation_mode' => 'TRANSACTIONS',
            'liquidity_level' => 'IMMEDIATE', 'include_in_net_worth' => false,
            'include_in_emergency_fund' => false, 'opened_on' => '2026-01-01', 'closed_on' => null,
            'version' => 1, 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ], [
            'include_in_net_worth' => ParameterType::BOOLEAN,
            'include_in_emergency_fund' => ParameterType::BOOLEAN,
        ]);
    }

    public function category(string $id, string $workspaceId, string $label, ?string $parentId, int $depth): void
    {
        $this->connection->insert('category_categories', [
            'id' => $id, 'workspace_id' => $workspaceId, 'type' => 'EXPENSE', 'label' => $label,
            'parent_id' => $parentId, 'icon' => null, 'color' => null, 'default_analytic_axes' => '[]',
            'budget_included' => true, 'sort_order' => 0, 'depth' => $depth, 'version' => 1,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ], [
            'budget_included' => ParameterType::BOOLEAN,
        ]);
    }
}
