<?php

declare(strict_types=1);

namespace App\Tests\Module\Catalog\Domain;

use App\Module\Catalog\Domain\EffectivePeriod;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\RuleValue;
use App\Module\Catalog\Domain\VerificationState;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductRuleTest extends TestCase
{
    public function testACeilingCannotBeRecordedAsARate(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new ProductRule(
            kind: RuleKind::DEPOSIT_CEILING,
            value: RuleValue::percentage(DecimalValue::fromString('1.7')),
            period: EffectivePeriod::openEndedFrom(CatalogFixture::day('2026-08-01')),
            source: CatalogFixture::source(),
            verifiedOn: CatalogFixture::day('2026-08-22'),
            verifiedBy: 'cadran-maintainer',
        );
    }

    public function testARateCannotBeRecordedAsAnAmount(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new ProductRule(
            kind: RuleKind::ANNUAL_RATE,
            value: RuleValue::amount(new AssetAmount(DecimalValue::fromString('1.7'), AssetCode::fromString('EUR'))),
            period: EffectivePeriod::openEndedFrom(CatalogFixture::day('2026-08-01')),
            source: CatalogFixture::source(),
            verifiedOn: CatalogFixture::day('2026-08-22'),
            verifiedBy: 'cadran-maintainer',
        );
    }

    public function testARateKeepsThePercentageTheSourcePublished(): void
    {
        $rule = CatalogFixture::rate('1.7', '2026-08-01', '2027-01-31');

        // The catalogue transports the figure as published. Nothing multiplies
        // it on the way to a screen, so no binary float ever touches a rate.
        self::assertSame('1.7', $rule->value->percentage?->toString());
    }

    public function testAVerificationTraceIsWholeOrAbsent(): void
    {
        $this->expectException(InvalidCatalogEntry::class);

        new ProductRule(
            kind: RuleKind::ANNUAL_RATE,
            value: RuleValue::percentage(DecimalValue::fromString('1.7')),
            period: EffectivePeriod::openEndedFrom(CatalogFixture::day('2026-08-01')),
            source: CatalogFixture::source(),
            verifiedOn: CatalogFixture::day('2026-08-22'),
            verifiedBy: null,
        );
    }

    #[DataProvider('verificationDays')]
    public function testFreshnessIsMeasuredAgainstTodayNotAgainstTheRulePeriod(string $today, VerificationState $expected): void
    {
        $rule = CatalogFixture::ceiling('22950.00', '2025-04-25', null, '2026-08-22');

        self::assertSame($expected, $rule->verificationOn(CatalogFixture::day($today)));
    }

    /**
     * @return iterable<string, array{string, VerificationState}>
     */
    public static function verificationDays(): iterable
    {
        yield 'read today' => ['2026-08-22', VerificationState::VERIFIED];
        yield 'inside the revision cycle' => ['2027-02-22', VerificationState::VERIFIED];
        yield 'a day past the cycle' => ['2027-02-23', VerificationState::STALE];
        yield 'long past the cycle' => ['2030-01-01', VerificationState::STALE];
    }

    public function testARuleNobodyCheckedIsReportedAsUnverified(): void
    {
        $rule = CatalogFixture::ceiling('22950.00', '2025-04-25', null, null);

        self::assertSame(VerificationState::UNVERIFIED, $rule->verificationOn(CatalogFixture::day('2026-09-02')));
    }
}
