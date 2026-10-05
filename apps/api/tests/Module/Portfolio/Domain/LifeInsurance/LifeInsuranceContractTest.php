<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\ContractStatus;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\LifeInsurance\ManagementMode;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LifeInsuranceContractTest extends TestCase
{
    private const string TODAY = '2026-10-05';

    public function testItOpensWithTheTaxStartDefaultingToTheSubscriptionDate(): void
    {
        $contract = self::open(taxStartOn: null);

        self::assertSame(ContractStatus::OPEN, $contract->status);
        self::assertNull($contract->closedOn);
        self::assertSame('2024-02-10', $contract->taxStartOn->format('Y-m-d'));
        self::assertSame(1, $contract->version);
    }

    public function testTheTaxStartMayPrecedeTheSubscriptionAfterAContractTransfer(): void
    {
        self::assertSame('2018-01-01', self::open(taxStartOn: '2018-01-01')->taxStartOn->format('Y-m-d'));
    }

    public function testTextFieldsAreTrimmed(): void
    {
        $contract = self::open(insurer: '  Assureur Démo  ', commercialName: ' Contrat Démo Vie ');

        self::assertSame('Assureur Démo', $contract->insurer);
        self::assertSame('Contrat Démo Vie', $contract->commercialName);
    }

    /** @return iterable<string, array{array<string, string|null>, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'empty insurer' => [['insurer' => '   '], 'invalid_contract_field'];
        yield 'long insurer' => [['insurer' => str_repeat('a', 81)], 'invalid_contract_field'];
        yield 'long distributor' => [['distributor' => str_repeat('a', 81)], 'invalid_contract_field'];
        yield 'empty commercial name' => [['commercialName' => ' '], 'invalid_contract_field'];
        yield 'long commercial name' => [['commercialName' => str_repeat('a', 121)], 'invalid_contract_field'];
        yield 'control character' => [['insurer' => "Assureur\x07"], 'invalid_contract_field'];
        yield 'newline' => [['commercialName' => "a\nb"], 'invalid_contract_field'];
        yield 'subscribed in the future' => [['subscribedOn' => '2026-10-06'], 'invalid_contract_date'];
        yield 'tax start in the future' => [['taxStartOn' => '2026-10-06'], 'invalid_contract_date'];
    }

    /** @param array<string, string|null> $overrides */
    #[DataProvider('invalidInputs')]
    public function testItRefusesInvalidInput(array $overrides, string $code): void
    {
        try {
            self::open(
                insurer: $overrides['insurer'] ?? 'Assureur Démo',
                distributor: $overrides['distributor'] ?? null,
                commercialName: $overrides['commercialName'] ?? null,
                subscribedOn: $overrides['subscribedOn'] ?? '2025-01-01',
                taxStartOn: $overrides['taxStartOn'] ?? null,
            );
            self::fail('The input must be refused.');
        } catch (InvalidLifeInsurance $exception) {
            self::assertSame($code, $exception->problemCode);
        }
    }

    public function testTodayIsAcceptedAsASubscriptionDate(): void
    {
        self::assertSame(self::TODAY, self::open(subscribedOn: self::TODAY, taxStartOn: null)->subscribedOn->format('Y-m-d'));
    }

    public function testUpdatingBumpsTheVersionAndKeepsTheNumberWhenOmitted(): void
    {
        $contract = self::open();
        $updated = $contract->revise(
            'Assureur Démo', null, 'Nouveau nom', $contract->numberSuffix, new \DateTimeImmutable('2024-02-10'),
            new \DateTimeImmutable('2024-02-10'), ManagementMode::MANAGED, new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable(self::TODAY),
        );

        self::assertSame(2, $updated->version);
        self::assertSame('2345', $updated->numberSuffix?->value);
        self::assertSame(ManagementMode::MANAGED, $updated->managementMode);
        self::assertSame(['commercialName', 'managementMode'], $updated->changedFields($contract));
    }

    public function testAnExplicitNullClearsTheNumber(): void
    {
        $contract = self::open();
        $updated = $contract->revise(
            $contract->insurer, null, $contract->commercialName, null, $contract->subscribedOn,
            $contract->taxStartOn, $contract->managementMode, new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable(self::TODAY),
        );

        self::assertNull($updated->numberSuffix);
        self::assertSame(['contractNumber'], $updated->changedFields($contract));
    }

    public function testFeeTermsCannotStartBeforeTheSubscription(): void
    {
        $contract = self::open();

        $contract->assertFeeTermsStart(new \DateTimeImmutable('2024-02-10'));
        $this->expectException(InvalidLifeInsurance::class);
        $contract->assertFeeTermsStart(new \DateTimeImmutable('2024-02-09'));
    }

    public function testRatesCannotPredateTheSubscriptionYear(): void
    {
        $contract = self::open();

        $contract->assertRateYear(2024, 2026);
        foreach ([2023, 2027] as $year) {
            try {
                $contract->assertRateYear($year, 2026);
                self::fail('The year must be refused.');
            } catch (InvalidLifeInsurance $exception) {
                self::assertSame('invalid_euro_fund_rate', $exception->problemCode);
            }
        }
    }

    private static function open(
        string $insurer = 'Assureur Démo',
        ?string $distributor = null,
        ?string $commercialName = 'Contrat Démo Vie',
        string $subscribedOn = '2024-02-10',
        ?string $taxStartOn = null,
    ): LifeInsuranceContract {
        return LifeInsuranceContract::open(
            id: '00000000-0000-7000-8000-0000000000c1',
            workspace: WorkspaceScope::fromString(WorkspaceFixture::OWN_WORKSPACE),
            accountId: '00000000-0000-7000-8000-0000000000d1',
            insurer: $insurer,
            distributor: $distributor,
            commercialName: $commercialName,
            numberSuffix: ContractNumberSuffix::fromNumber('AV-00012345'),
            subscribedOn: new \DateTimeImmutable($subscribedOn),
            taxStartOn: null === $taxStartOn ? null : new \DateTimeImmutable($taxStartOn),
            managementMode: ManagementMode::FREE,
            at: new \DateTimeImmutable('2026-10-05 10:00:00+00'),
            actorId: '00000000-0000-7000-8000-0000000000a1',
            today: new \DateTimeImmutable(self::TODAY),
        );
    }
}
