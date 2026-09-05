<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountCeiling;
use App\Module\Accounts\Domain\AccountRate;
use App\Module\Accounts\Domain\AccountTerm;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\TestCase;

/**
 * A rule is either published (verification and source) or declared by the
 * workspace (neither). A mixed pair would invent a publication the model
 * never had, or a freshness grade nobody computed — and a locally claimed
 * value can never be either, whatever else it carries.
 */
final class AccountRuleProvenanceTest extends TestCase
{
    public function testACeilingCannotCarryVerificationWithoutASource(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('published or declared');

        new AccountCeiling(
            kind: RuleKind::DEPOSIT_CEILING,
            amount: new AssetAmount(DecimalValue::fromString('22950'), AssetCode::fromString('EUR')),
            period: ProductModelFixture::period('2025-04-25'),
            verification: VerificationState::VERIFIED,
            source: null,
            accountAsset: AssetCode::fromString('EUR'),
        );
    }

    public function testACeilingCannotCarryASourceWithoutVerification(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('published or declared');

        new AccountCeiling(
            kind: RuleKind::DEPOSIT_CEILING,
            amount: new AssetAmount(DecimalValue::fromString('22950'), AssetCode::fromString('EUR')),
            period: ProductModelFixture::period('2025-04-25'),
            verification: null,
            source: CatalogFixture::source(),
            accountAsset: AssetCode::fromString('EUR'),
        );
    }

    public function testARateCannotCarryVerificationWithoutASource(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('published or declared');

        new AccountRate(
            kind: RuleKind::ANNUAL_RATE,
            scale: ProductModelFixture::tieredScale(),
            guaranteed: true,
            period: ProductModelFixture::period('2026-08-01'),
            verification: VerificationState::VERIFIED,
            source: null,
        );
    }

    public function testATermCannotCarryASourceWithoutVerification(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('published or declared');

        new AccountTerm(
            kind: RuleKind::INTEREST_ACCRUAL_METHOD,
            token: 'FORTNIGHTLY',
            period: ProductModelFixture::period('2026-01-01'),
            verification: null,
            source: CatalogFixture::source(),
        );
    }

    /**
     * A local claim and a publication are mutually exclusive. Presenting a
     * figure the holder typed as sourced is the one thing an override must
     * never make possible.
     */
    public function testALocallyClaimedCeilingCannotAlsoNameAPublication(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('names no publication');

        new AccountCeiling(
            kind: RuleKind::DEPOSIT_CEILING,
            amount: new AssetAmount(DecimalValue::fromString('30000'), AssetCode::fromString('EUR')),
            period: ProductModelFixture::period('2026-01-01'),
            verification: VerificationState::VERIFIED,
            source: CatalogFixture::source(),
            accountAsset: AssetCode::fromString('EUR'),
            override: AccountRuleOverrideFixture::ceiling(
                '00000000-0000-7000-8000-0000000000c1',
                '30000',
                '2026-01-01',
            ),
        );
    }

    public function testALocallyClaimedRateCannotAlsoNameAPublication(): void
    {
        $this->expectException(InvalidAccount::class);
        $this->expectExceptionMessage('names no publication');

        new AccountRate(
            kind: RuleKind::ANNUAL_RATE,
            scale: ProductModelFixture::tieredScale(),
            guaranteed: true,
            period: ProductModelFixture::period('2026-08-01'),
            verification: VerificationState::VERIFIED,
            source: CatalogFixture::source(),
            override: AccountRuleOverrideFixture::rate('00000000-0000-7000-8000-0000000000c1', '2026-08-01'),
        );
    }
}
