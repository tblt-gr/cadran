<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountProduct;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\RuleSchedule;
use App\Tests\Module\Catalog\Application\Double\InMemoryProductCatalog;
use App\Tests\Module\Catalog\Domain\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class AccountProductTest extends TestCase
{
    public function testAnAccountWithoutAProductNeedsNoCatalogueLookup(): void
    {
        self::assertNull($this->resolve(null, AccountKind::CURRENT, AccountValuationMode::TRANSACTIONS));
    }

    public function testAKnownProductAnswersItsOwnCode(): void
    {
        $code = $this->resolve('FR_LIVRET_A', AccountKind::SAVINGS, AccountValuationMode::TRANSACTIONS);

        self::assertSame('FR_LIVRET_A', $code?->toString());
    }

    /**
     * A code nobody owns and a code owned elsewhere are the same refusal: the
     * catalogue is global, so no workspace can hold a product another cannot
     * see, and the answer must not tell the two apart.
     */
    public function testAnUnknownOrMalformedProductIsRefusedIdentically(): void
    {
        $refusals = [];
        foreach (['FR_UNKNOWN_PRODUCT', 'fr livret a', 'X'] as $candidate) {
            try {
                $this->resolve($candidate, AccountKind::SAVINGS, AccountValuationMode::TRANSACTIONS);
                self::fail(sprintf('%s should be refused.', $candidate));
            } catch (InvalidAccountInput $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        self::assertSame([$refusals[0], $refusals[0], $refusals[0]], $refusals);
    }

    public function testTheKindMustBeTheOneTheProductDeclares(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('kind');

        // A PEA filed as plain savings would later be checked against a deposit
        // ceiling instead of its cumulative contributions.
        $this->resolve('FR_CTO', AccountKind::SAVINGS, AccountValuationMode::TRANSACTIONS);
    }

    public function testAValuationModeNeedsTheCapabilityThatFeedsIt(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('capability');

        $this->resolve('FR_LIVRET_A', AccountKind::SAVINGS, AccountValuationMode::PORTFOLIO);
    }

    public function testAProductThatHoldsPositionsAcceptsAPortfolioValuation(): void
    {
        $code = $this->resolve('FR_CTO', AccountKind::PORTFOLIO, AccountValuationMode::PORTFOLIO);

        self::assertSame('FR_CTO', $code?->toString());
    }

    private function resolve(?string $code, AccountKind $kind, AccountValuationMode $mode): ?ProductCode
    {
        $catalog = new InMemoryProductCatalog([
            new CatalogEntry(CatalogFixture::product(), RuleSchedule::empty()),
            new CatalogEntry(CatalogFixture::marketProduct(), RuleSchedule::empty()),
        ]);

        return (new AccountProduct($catalog))->resolve($code, $kind, $mode);
    }
}
