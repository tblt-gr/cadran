<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Application;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Portfolio\Application\OperationTypePolicy;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\LifeInsurance\ManagementMode;
use App\Module\Portfolio\Domain\OperationType;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationTypePolicyTest extends TestCase
{
    /** @return iterable<string, array{OperationType}> */
    public static function refusedOnAContract(): iterable
    {
        yield 'generic fee' => [OperationType::FEE];
        yield 'generic tax' => [OperationType::TAX];
        yield 'generic interest' => [OperationType::INTEREST];
        yield 'deposit' => [OperationType::DEPOSIT];
        yield 'withdrawal' => [OperationType::WITHDRAWAL];
        yield 'security transfer in' => [OperationType::SECURITY_TRANSFER_IN];
        yield 'security transfer out' => [OperationType::SECURITY_TRANSFER_OUT];
    }

    #[DataProvider('refusedOnAContract')]
    public function testSecuritiesAccountOperationsAreRefusedOnAContract(OperationType $type): void
    {
        try {
            OperationTypePolicy::assertAllowed(AccountKind::INSURANCE_CONTRACT, $type);
            self::fail('The operation must be refused.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('operation_type_not_allowed_for_account', $exception->problemCode);
        }
    }

    public function testOrdinaryTypesStayAvailableOnASecuritiesAccount(): void
    {
        foreach (OperationType::cases() as $type) {
            if (in_array($type, [OperationType::PREMIUM, OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER, OperationType::SWITCH, OperationType::EURO_FUND_INTEREST, OperationType::PROFIT_SHARING, OperationType::MANAGEMENT_FEE, OperationType::SOCIAL_CONTRIBUTION], true)) {
                try {
                    OperationTypePolicy::assertAllowed(AccountKind::PORTFOLIO, $type);
                    self::fail('Insurance operations require a contract account.');
                } catch (InvalidPortfolioOperation $exception) {
                    self::assertSame('operation_type_not_allowed_for_account', $exception->problemCode);
                }
                OperationTypePolicy::assertAllowed(AccountKind::INSURANCE_CONTRACT, $type);
                continue;
            }
            OperationTypePolicy::assertAllowed(AccountKind::PORTFOLIO, $type);
        }

        $this->addToAssertionCount(1);
    }

    public function testTradesStayAvailableOnAContract(): void
    {
        foreach ([OperationType::BUY, OperationType::SELL, OperationType::DIVIDEND] as $type) {
            OperationTypePolicy::assertAllowed(AccountKind::INSURANCE_CONTRACT, $type);
        }

        $this->addToAssertionCount(1);
    }

    public function testAnOperationOnAContractAccountNeedsTheContract(): void
    {
        try {
            OperationTypePolicy::assertContract(AccountKind::INSURANCE_CONTRACT, null, new \DateTimeImmutable('2026-03-01'));
            self::fail('The contract is required.');
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame('life_insurance_contract_required', $exception->problemCode);
        }
    }

    public function testASecuritiesAccountNeverNeedsAContract(): void
    {
        OperationTypePolicy::assertContract(AccountKind::PORTFOLIO, null, new \DateTimeImmutable('2026-03-01'));

        $this->addToAssertionCount(1);
    }

    public function testAnOperationAfterTheClosingDayIsRefused(): void
    {
        $closed = self::closedOn('2026-03-01');

        OperationTypePolicy::assertContract(AccountKind::INSURANCE_CONTRACT, $closed, new \DateTimeImmutable('2026-03-01'));
        $this->expectException(InvalidPortfolioOperation::class);
        $this->expectExceptionMessage('closed');
        OperationTypePolicy::assertContract(AccountKind::INSURANCE_CONTRACT, $closed, new \DateTimeImmutable('2026-03-02'));
    }

    private static function closedOn(string $day): LifeInsuranceContract
    {
        $at = new \DateTimeImmutable('2026-01-01');

        return new LifeInsuranceContract(
            '00000000-0000-7000-8000-0000000000c1', WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1',
            'Assureur Démo', null, null, ContractNumberSuffix::fromNumber('AV-00012345'), $at, $at, ManagementMode::FREE,
            \App\Module\Portfolio\Domain\LifeInsurance\ContractStatus::CLOSED, new \DateTimeImmutable($day), $at,
            WorkspaceFixture::OWNER_ID, $at, 1,
        );
    }
}
