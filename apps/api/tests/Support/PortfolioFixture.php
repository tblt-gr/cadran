<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/** Seeds portfolio accounts, instruments and transfer legs straight into the tables. */
final readonly class PortfolioFixture
{
    public const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';
    public const string CASH_ACCOUNT = '00000000-0000-7000-8000-0000000000d2';
    public const string ETF = '00000000-0000-7000-8000-000000000b01';
    public const string STOCK = '00000000-0000-7000-8000-000000000b02';

    public function __construct(private Connection $connection)
    {
    }

    public function account(
        string $id = self::ACCOUNT,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $label = 'Compte-titres',
        string $kind = 'PORTFOLIO',
        string $valuationMode = 'PORTFOLIO',
        string $assetCode = 'EUR',
        string $openedOn = '2026-01-01',
        ?string $closedOn = null,
        bool $archived = false,
    ): void {
        $this->connection->insert('account_financial_accounts', [
            'id' => $id, 'workspace_id' => $workspace, 'label' => $label, 'asset_code' => $assetCode, 'kind' => $kind,
            'masked_identifier' => null, 'valuation_mode' => $valuationMode, 'liquidity_level' => 'IMMEDIATE',
            'include_in_net_worth' => false, 'include_in_emergency_fund' => false, 'opened_on' => $openedOn,
            'closed_on' => $closedOn, 'archived_at' => $archived ? '2026-03-01 00:00:00+00' : null,
            'version' => 1, 'created_at' => '2026-03-14 09:00:00+00', 'updated_at' => '2026-03-14 09:00:00+00',
        ], ['include_in_net_worth' => ParameterType::BOOLEAN, 'include_in_emergency_fund' => ParameterType::BOOLEAN]);
    }

    public function instrument(
        string $id = self::ETF,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $name = 'ETF Monde',
        ?string $isin = null,
        string $currency = 'EUR',
        int $quantityPrecision = 6,
        int $pricePrecision = 4,
        string $status = 'ACTIVE',
    ): void {
        $owner = WorkspaceFixture::OTHER_WORKSPACE === $workspace ? WorkspaceFixture::OTHER_OWNER_ID : WorkspaceFixture::OWNER_ID;
        $this->connection->insert('portfolio_instruments', [
            'id' => $id, 'workspace_id' => $workspace, 'name' => $name, 'normalized_name' => mb_strtolower($name),
            'isin' => $isin, 'ticker' => null === $isin ? 'T'.strtoupper(substr($id, -3)) : null, 'venue_mic' => null,
            'instrument_type' => 'ETF', 'asset_class' => null, 'region' => null, 'currency_code' => $currency,
            'quantity_precision' => $quantityPrecision, 'price_precision' => $pricePrecision, 'source' => 'MANUAL',
            'reviewed_at' => '2026-01-01 09:00:00+00', 'reviewed_by' => $owner, 'status' => $status, 'used_at' => null,
            'created_at' => '2026-01-01 09:00:00+00', 'created_by' => $owner, 'updated_at' => '2026-01-01 09:00:00+00',
            'version' => 1,
        ]);
    }

    public function transferLeg(
        string $id,
        string $accountId,
        string $amount,
        string $bookedOn,
        string $workspace = WorkspaceFixture::OWN_WORKSPACE,
        string $state = 'BOOKED',
        string $nature = 'TRANSFER',
        string $label = 'Investissement mensuel',
    ): void {
        $this->connection->insert('transaction_transactions', [
            'id' => $id, 'workspace_id' => $workspace, 'account_id' => $accountId, 'asset_code' => 'EUR',
            'amount_value' => $amount, 'amount_scale' => strlen(explode('.', $amount)[1] ?? ''), 'state' => $state,
            'nature' => $nature, 'source' => 'MANUAL', 'booked_on' => $bookedOn, 'raw_label' => $label, 'version' => 1,
            'created_at' => '2026-03-14 09:12:04+00', 'updated_at' => '2026-03-14 09:12:04+00',
            'voided_at' => 'VOIDED' === $state ? '2026-03-15 09:12:04+00' : null,
        ]);
    }
}
