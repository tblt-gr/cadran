<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountRuleAuthority;
use App\Module\Accounts\Domain\AccountRuleOverride;
use App\Module\Accounts\Domain\DeclaredRuleValue;
use App\Module\Accounts\Domain\InvalidAccountRuleOverride;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Catalog\Domain\YieldKind;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One local claim: what it has to carry to be recorded at all, and what
 * withdrawing it does.
 */
final class AccountRuleOverrideTest extends TestCase
{
    public function testAnOverrideCarriesItsDatesItsReasonAndItsAuthor(): void
    {
        $override = AccountRuleOverrideFixture::ceiling(
            '00000000-0000-7000-8000-0000000000c1',
            '30000.00',
            '2026-01-01',
            '2026-12-31',
        );

        self::assertTrue($override->isStanding());
        self::assertSame('2026-01-01', $override->period->validFrom->format('Y-m-d'));
        self::assertSame('2026-12-31', $override->period->validTo?->format('Y-m-d'));
        self::assertNotSame('', $override->reason);
        self::assertSame(AccountRuleOverrideFixture::AUTHOR, $override->authorId);
    }

    /**
     * An unexplained local figure sitting beside a published one is the drift
     * an override exists to make visible. A blank reason would hide it again.
     */
    #[DataProvider('unusableReasons')]
    public function testAReasonIsRequiredAndBounded(string $reason): void
    {
        $this->expectException(InvalidAccountRuleOverride::class);

        $this->override(reason: $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'untrimmed' => [' Negotiated rate '];
        yield 'control character' => ["Negotiated\nrate"];
        yield 'too long' => [str_repeat('a', AccountRuleOverride::MAX_REASON_LENGTH + 1)];
    }

    public function testAValueThatDoesNotMatchItsRuleKindIsRefused(): void
    {
        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('carries a');

        $this->override(kind: RuleKind::ANNUAL_RATE);
    }

    public function testAWithdrawnOverrideNamesWhoWithdrewItAndStopsApplying(): void
    {
        $override = AccountRuleOverrideFixture::ceiling(
            '00000000-0000-7000-8000-0000000000c1',
            '30000.00',
            '2026-01-01',
        );

        $withdrawn = $override->withdrawnBy(
            AccountRuleOverrideFixture::OTHER_AUTHOR,
            new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
        );

        self::assertTrue($override->appliesOn(ProductModelFixture::day('2026-06-01')));
        self::assertFalse($withdrawn->isStanding());
        self::assertFalse($withdrawn->appliesOn(ProductModelFixture::day('2026-06-01')));
        self::assertSame(AccountRuleOverrideFixture::OTHER_AUTHOR, $withdrawn->withdrawnBy);
        // The claim itself is untouched: the trail still shows what was said.
        self::assertSame($override->reason, $withdrawn->reason);
        self::assertSame($override->authorId, $withdrawn->authorId);
    }

    public function testAnOverrideCannotBeWithdrawnTwice(): void
    {
        $withdrawn = AccountRuleOverrideFixture::ceiling(
            '00000000-0000-7000-8000-0000000000c1',
            '30000.00',
            '2026-01-01',
            withdrawnAt: new \DateTimeImmutable('2026-09-10T09:00:00+00:00'),
        );

        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('already withdrawn');

        $withdrawn->withdrawnBy(
            AccountRuleOverrideFixture::AUTHOR,
            new \DateTimeImmutable('2026-09-11T09:00:00+00:00'),
        );
    }

    /**
     * Attaching a rate to a market account through the override door would
     * turn an assumption into a promise. It is the same refusal the catalogue
     * and the workspace model already make, applied to the third writer.
     */
    public function testAnAuthorityThatPromisesNoRateRefusesARateOverride(): void
    {
        $authority = AccountRuleAuthority::ofModel(ProductModelFixture::model(
            yieldKind: YieldKind::MARKET,
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
            ),
        ));

        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('earns no stated rate');

        $authority->assertMayState(RuleKind::ANNUAL_RATE);
    }

    public function testAnAuthorityWithoutTheCapabilityRefusesTheRuleKind(): void
    {
        $authority = AccountRuleAuthority::ofModel(ProductModelFixture::model(
            capabilities: ProductCapabilities::of(
                ProductCapability::SUPPORTS_BALANCE,
                ProductCapability::SUPPORTS_TRANSACTIONS,
                ProductCapability::SUPPORTS_INTEREST,
            ),
        ));

        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('SUPPORTS_CONTRIBUTIONS');

        $authority->assertMayState(RuleKind::CONTRIBUTION_CEILING);
    }

    /**
     * A local ceiling is checked against this account and nothing else, and no
     * conversion ships with the application. A figure in another unit could
     * only ever be displayed as not comparable.
     */
    public function testAnAmountInAnotherUnitThanTheAccountIsRefused(): void
    {
        $authority = AccountRuleAuthority::ofModel(ProductModelFixture::model());

        $this->expectException(InvalidAccountRuleOverride::class);
        $this->expectExceptionMessage('EUR');

        $authority->assertDenominatedIn(
            new AssetAmount(DecimalValue::fromString('30000'), AssetCode::fromString('USD')),
            AssetCode::fromString('EUR'),
        );
    }

    private function override(
        string $reason = 'Negotiated with the branch.',
        RuleKind $kind = RuleKind::DEPOSIT_CEILING,
    ): AccountRuleOverride {
        return new AccountRuleOverride(
            id: '00000000-0000-7000-8000-0000000000c1',
            workspace: WorkspaceScope::fromString(AccountFixture::WORKSPACE),
            accountId: AccountFixture::ID,
            kind: $kind,
            value: DeclaredRuleValue::amount(new AssetAmount(
                DecimalValue::fromString('30000'),
                AssetCode::fromString('EUR'),
            )),
            period: ProductModelFixture::period('2026-01-01'),
            reason: $reason,
            authorId: AccountRuleOverrideFixture::AUTHOR,
            recordedAt: new \DateTimeImmutable(AccountRuleOverrideFixture::RECORDED_AT),
        );
    }
}
