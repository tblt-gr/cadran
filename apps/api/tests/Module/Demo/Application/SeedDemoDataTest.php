<?php

declare(strict_types=1);

namespace App\Tests\Module\Demo\Application;

use App\Module\Demo\Application\DemoCents;
use App\Module\Demo\Application\DemoRandom;
use App\Module\Demo\Application\DemoSeedRefused;
use App\Module\Demo\Application\ExtendDemoData;
use App\Module\Demo\Application\SeedDemoData;
use App\Module\Identity\Domain\PasswordHasher;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SeedDemoDataTest extends KernelTestCase
{
    private Connection $connection;
    private WorkspaceFixture $fixture;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        // Debug mode would keep every query backtrace of a full year of writes in memory.
        self::bootKernel(['debug' => false]);
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $hasher = self::getContainer()->get(PasswordHasher::class);
        self::assertInstanceOf(PasswordHasher::class, $hasher);
        $this->fixture->seed($hasher);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testTheSeedKeepsTransfersNeutralAndEveryClosedMonthReconciled(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);

        $summary = $seed(WorkspaceFixture::OWNER_EMAIL);

        self::assertSame(4, $summary->accounts);
        self::assertSame(20, $this->rows(
            'SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ?',
            [$workspace = WorkspaceFixture::OWN_WORKSPACE],
        ));
        self::assertSame(2, $this->rows(
            "SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ? AND status = 'INACTIVE'",
            [$workspace],
        ));
        self::assertSame(20, $this->rows(
            "SELECT COUNT(*) FROM portfolio_instruments WHERE workspace_id = ? AND source = 'MANUAL' AND reviewed_at IS NOT NULL",
            [$workspace],
        ));
        self::assertSame(
            [
                ['Ancien fonds Démo', 'FRDEMO000056', null, 'FUND', 'MIXED', 'EUROPE', 'INACTIVE'],
                ['Fonds Obligataire Démo', 'FRDEMO000031', null, 'FUND', 'BOND', 'EUROPE', 'ACTIVE'],
            ],
            array_map(array_values(...), $this->connection->fetchAllAssociative(
                "SELECT name, isin, ticker, instrument_type, asset_class, region, status FROM portfolio_instruments
                 WHERE workspace_id = ? AND isin IN ('FRDEMO000031', 'FRDEMO000056') ORDER BY name",
                [$workspace],
            )),
        );
        self::assertGreaterThan(0, $summary->transfers);
        // Both legs of every transfer cancel exactly, so transfers never move net worth.
        self::assertTrue($this->connection->fetchOne(
            "SELECT COALESCE(SUM(amount_value), 0) = 0 FROM transaction_transactions WHERE workspace_id = ? AND nature = 'TRANSFER'",
            [$workspace],
        ));
        self::assertSame($summary->closedMonths, $this->rows(
            'SELECT COUNT(*) FROM account_period_closures WHERE workspace_id = ?',
            [$workspace],
        ));
        // Every account, including the contract valued from its holdings, is reconciled before closing.
        self::assertSame(4 * $summary->closedMonths + 1, $this->rows(
            "SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = ? AND reconciliation_status = 'RECONCILED' AND as_of = (date_trunc('month', as_of) + interval '1 month - 1 day')::date",
            [$workspace],
        ));
        // The extra end-of-day deposit observation also publishes a reconciled calculated snapshot.
        self::assertSame(1, $this->rows(
            "SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = ? AND source = 'CALCULATED' AND reconciliation_status = 'RECONCILED' AND superseded_at IS NULL AND as_of = (date_trunc('month', current_date) - interval '1 month' + interval '6 days')::date",
            [$workspace],
        ));
    }

    public function testTheDemoLifeInsuranceContractCarriesItsSupportsFeeTermsAndRates(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $workspace = WorkspaceFixture::OWN_WORKSPACE;

        self::assertSame(
            ['Assurance-vie', 'INSURANCE_CONTRACT', 'FR_LIFE_INSURANCE', 'PORTFOLIO', 'Assureur Démo', '2023-12-31'],
            array_values((array) $this->connection->fetchAssociative(
                "SELECT label, kind, product_code, valuation_mode, institution, opened_on::text FROM account_financial_accounts WHERE workspace_id = ? AND kind = 'INSURANCE_CONTRACT'",
                [$workspace],
            )),
        );
        self::assertSame(
            ['Assureur Démo', 'Contrat Démo Vie', '2345', 'FREE', 'OPEN'],
            array_values((array) $this->connection->fetchAssociative(
                'SELECT insurer, commercial_name, contract_number_suffix, management_mode, status FROM portfolio_life_insurance_contracts WHERE workspace_id = ?',
                [$workspace],
            )),
        );
        self::assertSame(
            [['Fonds Actions Démo', 'FRDEMO000064', 'FUND', 'EQUITY', 6, 4], ['Fonds euros Démo', null, 'EURO_FUND', null, 2, 0]],
            array_map(array_values(...), $this->connection->fetchAllAssociative(
                "SELECT name, isin, instrument_type, asset_class, quantity_precision, price_precision FROM portfolio_instruments WHERE workspace_id = ? AND name IN ('Fonds euros Démo', 'Fonds Actions Démo') ORDER BY name",
                [$workspace],
            )),
        );
        self::assertTrue($this->connection->fetchOne(
            'SELECT entry_fee_rate_value = 1 AND euro_fund_management_fee_rate_value = 0.60 AND unit_linked_management_fee_rate_value = 0.85 AND switch_fee_rate_value = 0.50 AND valid_from = DATE \'2023-12-31\' FROM portfolio_life_insurance_fee_terms WHERE workspace_id = ?',
            [$workspace],
        ));
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM portfolio_euro_fund_rates WHERE workspace_id = ? AND served_rate_value IN (2.50, 2.80)', [$workspace]));
        $years = $this->connection->fetchFirstColumn('SELECT year FROM portfolio_euro_fund_rates WHERE workspace_id = ? ORDER BY year DESC', [$workspace]);
        $current = (int) (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
        self::assertSame([$current - 1, $current - 2], array_map(static function (mixed $year): int {
            self::assertIsInt($year);

            return $year;
        }, $years));
        self::assertSame(['NET_OF_MANAGEMENT_FEES'], $this->connection->fetchFirstColumn('SELECT DISTINCT basis FROM portfolio_euro_fund_rates WHERE workspace_id = ?', [$workspace]));
        self::assertGreaterThan(0, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'PREMIUM'", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'PARTIAL_SURRENDER' AND gross_amount_value = 1050 AND taxes_value = 16.10", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'PREMIUM' AND gross_amount_value = 10000 AND fees_value = 100 AND cash_amount_value = 0", [$workspace]));
        self::assertGreaterThan(0, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'PREMIUM' AND gross_amount_value = 1500 AND fees_value = 15 AND linked_transaction_id IS NOT NULL", [$workspace]));
        self::assertSame(2, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'EURO_FUND_INTEREST' AND gross_amount_value > taxes_value AND cash_amount_value = 0", [$workspace]));
        self::assertSame(2, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'MANAGEMENT_FEE' AND quantity_value = 0.30 AND cash_amount_value = 0", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'EURO_FUND_INTEREST' AND interest_basis = 'UNKNOWN'", [$workspace]));
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM audit_events WHERE concat(before_json::text, after_json::text) LIKE '%00012345%'"));
    }

    public function testTheDemoPortfolioMirrorsEveryMonthlyInvestmentTransferAndShowsTheLifecycleCases(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $workspace = WorkspaceFixture::OWN_WORKSPACE;

        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM account_financial_accounts WHERE workspace_id = ? AND label = 'Compte-titres' AND valuation_mode = 'PORTFOLIO'", [$workspace]));

        // The opening deposit equals the opening balance and is not linked to any leg.
        self::assertSame('5000.00', $this->connection->fetchOne(
            "SELECT trim(trailing '0' from gross_amount_value::text)||'00' FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NULL AND state = 'ACTIVE'",
            [$workspace],
        ));
        // Every monthly investment leg backs one linked deposit, bought into the same day.
        $legs = $this->rows("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND raw_label = 'Investissement mensuel' AND amount_value > 0", [$workspace]);
        self::assertGreaterThan(0, $legs);
        self::assertSame($legs, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'DEPOSIT' AND linked_transaction_id IS NOT NULL AND state = 'ACTIVE'", [$workspace]));
        self::assertSame($legs, $this->rows(
            "SELECT COUNT(*) FROM portfolio_operations d JOIN portfolio_operations b ON b.account_id = d.account_id AND b.trade_date = d.trade_date AND b.operation_type = 'BUY' AND b.reference = 'Investissement mensuel' WHERE d.workspace_id = ? AND d.operation_type = 'DEPOSIT' AND d.linked_transaction_id IS NOT NULL",
            [$workspace],
        ));
        foreach (['DIVIDEND', 'FEE', 'SELL', 'SPLIT'] as $type) {
            self::assertGreaterThan(0, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = '$type' AND state = 'ACTIVE'", [$workspace]), $type);
        }
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'FEE' AND state = 'VOIDED' AND void_kind = 'VOIDED'", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'BUY' AND void_kind = 'REPLACED'", [$workspace]));
        // One incoming transfer leg is deliberately left unlinked, and one withdrawal leaves the portfolio, so the cash view
        // shows an UNLINKED_TRANSFER_IN warning and a Retraits total.
        self::assertSame(1, $this->rows(
            "SELECT COUNT(*) FROM transaction_transactions t JOIN account_financial_accounts a ON a.id = t.account_id AND a.workspace_id = t.workspace_id
             WHERE t.workspace_id = ? AND a.valuation_mode = 'PORTFOLIO' AND t.nature = 'TRANSFER' AND t.amount_value > 0 AND t.voided_at IS NULL
               AND NOT EXISTS (SELECT 1 FROM portfolio_operations o WHERE o.workspace_id = t.workspace_id AND o.linked_transaction_id = t.id AND o.state = 'ACTIVE')",
            [$workspace],
        ));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_operations WHERE workspace_id = ? AND operation_type = 'WITHDRAWAL' AND state = 'ACTIVE'", [$workspace]));
        // The demo never books a transaction for a buy, a sell or a fee of the portfolio.
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND raw_label IN ('Achat initial', 'Droits de garde', 'Vente partielle')", [$workspace]));
    }

    public function testTheDemoPortfolioIsValuedByItsValuationsInEveryState(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $workspace = WorkspaceFixture::OWN_WORKSPACE;
        $active = 'workspace_id = ? AND superseded_at IS NULL';

        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM account_balance_snapshots s JOIN account_financial_accounts a ON a.id = s.account_id WHERE s.workspace_id = ? AND a.valuation_mode = 'PORTFOLIO' AND s.source = 'MANUAL'", [$workspace]));
        self::assertSame(3, $this->rows("SELECT COUNT(*) FROM portfolio_valuations WHERE $active AND account_id IN (SELECT id FROM account_financial_accounts WHERE kind = 'PORTFOLIO') AND detail = 'POSITIONS' AND completeness = 'COMPLETE'", [$workspace]));
        self::assertGreaterThan(3, $this->rows("SELECT COUNT(*) FROM portfolio_valuations WHERE $active AND detail = 'TOTAL'", [$workspace]));
        self::assertSame(1, $this->rows("SELECT COUNT(*) FROM portfolio_valuations WHERE $active AND completeness = 'PARTIAL' AND total_value_value IS NULL", [$workspace]));
        // A price keeps the date it was observed on: an instrument last priced long ago is honestly stale.
        self::assertGreaterThanOrEqual(1, $this->rows("SELECT COUNT(DISTINCT s.valuation_id) FROM portfolio_position_snapshots s JOIN portfolio_valuations v ON v.id = s.valuation_id WHERE s.workspace_id = ? AND s.price_quality = 'STALE' AND v.completeness = 'COMPLETE'", [$workspace]));
        self::assertSame(
            $this->rows("SELECT COUNT(*) FROM portfolio_valuations WHERE $active AND completeness = 'COMPLETE'", [$workspace]),
            $this->rows("SELECT COUNT(*) FROM account_balance_snapshots WHERE workspace_id = ? AND source = 'CALCULATED' AND superseded_at IS NULL", [$workspace]),
        );
    }

    public function testASecondRunIsRefusedAndWritesNothing(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $before = $this->rows('SELECT COUNT(*) FROM transaction_transactions');

        try {
            $seed(WorkspaceFixture::OWNER_EMAIL);
            self::fail('A populated workspace must refuse the seed.');
        } catch (DemoSeedRefused) {
            self::assertSame($before, $this->rows('SELECT COUNT(*) FROM transaction_transactions'));
        }
    }

    public function testExtendingASeededWorkspaceIsIdempotentAndRestoresMissingOpenMonthEntries(): void
    {
        $seed = self::getContainer()->get(SeedDemoData::class);
        self::assertInstanceOf(SeedDemoData::class, $seed);
        $extend = self::getContainer()->get(ExtendDemoData::class);
        self::assertInstanceOf(ExtendDemoData::class, $extend);
        $seed(WorkspaceFixture::OWNER_EMAIL);
        $live = "SELECT COUNT(*) FROM transaction_transactions WHERE workspace_id = ? AND voided_at IS NULL AND booked_on >= date_trunc('month', current_date)";
        $workspace = WorkspaceFixture::OWN_WORKSPACE;
        $before = $this->rows($live, [$workspace]);

        // Seeded today, nothing is missing: the extension writes nothing.
        $extend(WorkspaceFixture::OWNER_EMAIL);
        self::assertSame($before, $this->rows($live, [$workspace]));

        // As if the seed had run days ago: direct debits of the open month are gone, the extension brings them back once.
        $this->connection->executeStatement(
            "UPDATE transaction_transactions SET voided_at = now(), state = 'VOIDED' WHERE workspace_id = ? AND raw_label LIKE 'PRLV %' AND booked_on >= date_trunc('month', current_date)",
            [$workspace],
        );
        $extend(WorkspaceFixture::OWNER_EMAIL);
        self::assertSame($before, $this->rows($live, [$workspace]));
        $extend(WorkspaceFixture::OWNER_EMAIL);
        self::assertSame($before, $this->rows($live, [$workspace]));
    }

    public function testCentsFormatExactlyAndTheGeneratorIsReproducible(): void
    {
        self::assertSame('-720.00', DemoCents::format(-72000));
        self::assertSame('0.05', DemoCents::format(5));
        self::assertSame('-0.99', DemoCents::format(-99));
        $first = new DemoRandom(1);
        $second = new DemoRandom(1);
        self::assertSame($first->between(1, 1000), $second->between(1, 1000));
    }

    /** @param list<string> $parameters */
    private function rows(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        self::assertIsInt($value);

        return $value;
    }
}
