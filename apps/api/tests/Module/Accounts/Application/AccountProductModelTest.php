<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\AccountProductModel;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Catalog\Domain\ProductCapabilities;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Tests\Module\Accounts\Application\Double\InMemoryProductModelRepository;
use App\Tests\Module\Accounts\Domain\ProductModelFixture;
use PHPUnit\Framework\TestCase;

final class AccountProductModelTest extends TestCase
{
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';

    public function testAnAccountWithoutAModelNeedsNoRepositoryLookup(): void
    {
        self::assertNull($this->resolve(null, AccountKind::SAVINGS, AccountValuationMode::TRANSACTIONS));
    }

    public function testAKnownModelOfTheCallingWorkspaceAnswersItsOwnId(): void
    {
        $id = $this->resolve(ProductModelFixture::ID, AccountKind::SAVINGS, AccountValuationMode::TRANSACTIONS);

        self::assertSame(ProductModelFixture::ID, $id);
    }

    /**
     * An unknown identifier and one belonging to another workspace answer
     * alike: the repository scopes the lookup, so neither refusal confirms
     * that the identifier is real.
     */
    public function testAnUnknownMalformedOrForeignModelIsRefusedIdentically(): void
    {
        $refusals = [];
        foreach (['00000000-0000-7000-8000-0000000000ff', 'not-a-uuid', ProductModelFixture::ID] as $candidate) {
            try {
                $this->resolve(
                    $candidate,
                    AccountKind::SAVINGS,
                    AccountValuationMode::TRANSACTIONS,
                    caller: 'not-a-uuid' === $candidate ? ProductModelFixture::WORKSPACE : self::OTHER_WORKSPACE,
                );
                self::fail(sprintf('%s should be refused.', $candidate));
            } catch (InvalidAccountInput $refusal) {
                $refusals[] = $refusal->getMessage();
            }
        }

        self::assertSame([$refusals[0], $refusals[0], $refusals[0]], $refusals);
    }

    public function testAnArchivedModelCannotBackANewAccount(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('archived model');

        $this->resolve(
            ProductModelFixture::ID,
            AccountKind::SAVINGS,
            AccountValuationMode::TRANSACTIONS,
            model: ProductModelFixture::model(archivedAt: new \DateTimeImmutable('2026-09-04T09:00:00+00:00')),
        );
    }

    public function testTheKindMustBeTheOneTheModelDeclares(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('kind');

        $this->resolve(ProductModelFixture::ID, AccountKind::CURRENT, AccountValuationMode::TRANSACTIONS);
    }

    public function testAValuationModeNeedsTheCapabilityThatFeedsIt(): void
    {
        $this->expectException(InvalidAccountInput::class);
        $this->expectExceptionMessage('capability');

        $this->resolve(ProductModelFixture::ID, AccountKind::SAVINGS, AccountValuationMode::PORTFOLIO);
    }

    public function testAModelThatHoldsPositionsAcceptsAPortfolioValuation(): void
    {
        $id = $this->resolve(
            ProductModelFixture::ID,
            AccountKind::PORTFOLIO,
            AccountValuationMode::PORTFOLIO,
            model: ProductModelFixture::model(
                family: AccountKind::PORTFOLIO,
                capabilities: ProductCapabilities::of(
                    ProductCapability::SUPPORTS_BALANCE,
                    ProductCapability::SUPPORTS_TRANSACTIONS,
                    ProductCapability::SUPPORTS_HOLDINGS,
                ),
                valuationMode: AccountValuationMode::PORTFOLIO,
            ),
        );

        self::assertSame(ProductModelFixture::ID, $id);
    }

    private function resolve(
        ?string $id,
        AccountKind $kind,
        AccountValuationMode $mode,
        string $caller = ProductModelFixture::WORKSPACE,
        ?ProductModel $model = null,
    ): ?string {
        $models = new InMemoryProductModelRepository($model ?? ProductModelFixture::model());

        return (new AccountProductModel($models))->resolve(WorkspaceScope::fromString($caller), $id, $kind, $mode);
    }
}
