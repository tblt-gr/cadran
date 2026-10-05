<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateBasis;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\LifeInsurance\ManagementMode;
use App\Module\Portfolio\Infrastructure\Persistence\DbalEuroFundRateRepository;
use App\Module\Portfolio\Infrastructure\Persistence\DbalFeeTermsRepository;
use App\Module\Portfolio\Infrastructure\Persistence\DbalLifeInsuranceContractRepository;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LifeInsurancePersistenceTest extends KernelTestCase
{
    private const string CONTRACT = '00000000-0000-7000-8000-0000000000c1';
    private const string FUND = '00000000-0000-7000-8000-000000000b03';
    private const string ACTOR = WorkspaceFixture::OWNER_ID;

    private Connection $connection;
    private WorkspaceFixture $fixture;
    private DbalLifeInsuranceContractRepository $contracts;
    private DbalFeeTermsRepository $feeTerms;
    private DbalEuroFundRateRepository $rates;

    protected function setUp(): void
    {
        WorkspaceFixture::requireDatabase();
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;
        $this->contracts = new DbalLifeInsuranceContractRepository($connection);
        $this->feeTerms = new DbalFeeTermsRepository($connection);
        $this->rates = new DbalEuroFundRateRepository($connection);
        $this->fixture = new WorkspaceFixture($connection);
        $this->fixture->reset();
        $this->fixture->seed();
        $portfolio = new PortfolioFixture($connection);
        $portfolio->account(label: 'Assurance-vie', kind: 'INSURANCE_CONTRACT');
        $portfolio->account(PortfolioFixture::CASH_ACCOUNT, WorkspaceFixture::OTHER_WORKSPACE, 'Voisin', kind: 'INSURANCE_CONTRACT');
        $portfolio->euroFund(self::FUND);
    }

    protected function tearDown(): void
    {
        $this->fixture->reset();
        parent::tearDown();
    }

    public function testAContractRoundTripsAndStaysInsideItsWorkspace(): void
    {
        $this->contracts->add($this->contract());

        $stored = $this->contracts->findByAccount(WorkspaceFixture::own(), PortfolioFixture::ACCOUNT);
        self::assertNotNull($stored);
        self::assertSame('2345', $stored->numberSuffix?->value);
        self::assertSame('2024-02-10', $stored->taxStartOn->format('Y-m-d'));
        self::assertNull($this->contracts->find(WorkspaceFixture::other(), self::CONTRACT));
        self::assertNull($this->contracts->findByAccount(WorkspaceFixture::other(), PortfolioFixture::ACCOUNT));
        self::assertNull($this->contracts->findForUpdate(WorkspaceFixture::other(), self::CONTRACT));
    }

    public function testOnlyOneContractExistsPerAccount(): void
    {
        $this->contracts->add($this->contract());

        $this->expectException(UniqueConstraintViolationException::class);
        $this->contracts->add($this->contract('00000000-0000-7000-8000-0000000000c2'));
    }

    public function testAnUpdateNeedsTheExpectedVersion(): void
    {
        $contract = $this->contract();
        $this->contracts->add($contract);
        $revised = $contract->revise(
            'Assureur Démo', null, 'Autre nom', null, $contract->subscribedOn, $contract->taxStartOn, ManagementMode::PILOTED,
            new \DateTimeImmutable('2026-10-06'), new \DateTimeImmutable('2026-10-06'),
        );

        self::assertFalse($this->contracts->update($revised, 7));
        self::assertTrue($this->contracts->update($revised, 1));
        $stored = $this->contracts->find(WorkspaceFixture::own(), self::CONTRACT);
        self::assertSame(2, $stored?->version);
        self::assertNull($stored->numberSuffix);
        self::assertSame(ManagementMode::PILOTED, $stored->managementMode);
    }

    public function testAnAccountCannotCarryAContractOfAnotherWorkspace(): void
    {
        $foreign = new LifeInsuranceContract(
            self::CONTRACT, WorkspaceFixture::own(), PortfolioFixture::CASH_ACCOUNT, 'Assureur Démo', null, null, null,
            new \DateTimeImmutable('2024-02-10'), new \DateTimeImmutable('2024-02-10'), ManagementMode::FREE,
            \App\Module\Portfolio\Domain\LifeInsurance\ContractStatus::OPEN, null, new \DateTimeImmutable('2026-10-05'), self::ACTOR,
            new \DateTimeImmutable('2026-10-05'), 1,
        );

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $this->contracts->add($foreign);
    }

    public function testFeeTermsKeepTheirExactDecimalsAndOrderMostRecentFirst(): void
    {
        $this->contracts->add($this->contract());
        $this->feeTerms->add($this->terms('00000000-0000-7000-8000-0000000000f1', '2024-02-10', '1.0000', '0.8512', '2.50'));
        $this->feeTerms->add($this->terms('00000000-0000-7000-8000-0000000000f2', '2026-01-01', '0.5', null, null));

        $listed = $this->feeTerms->listForContract(WorkspaceFixture::own(), self::CONTRACT);

        self::assertSame(['2026-01-01', '2024-02-10'], array_map(static fn (FeeTerms $terms): string => $terms->validFrom->format('Y-m-d'), $listed));
        self::assertSame('1.0000', $listed[1]->entryFeeRate?->toString());
        self::assertSame('0.8512', $listed[1]->euroFundManagementFeeRate?->toString());
        self::assertSame('2.50', $listed[1]->switchFeeFixed?->toString());
        self::assertNull($listed[0]->euroFundManagementFeeRate);
        self::assertSame(2, $this->feeTerms->count(WorkspaceFixture::own(), self::CONTRACT));
        self::assertTrue($this->feeTerms->existsFrom(WorkspaceFixture::own(), self::CONTRACT, new \DateTimeImmutable('2026-01-01')));
        self::assertFalse($this->feeTerms->existsFrom(WorkspaceFixture::own(), self::CONTRACT, new \DateTimeImmutable('2026-01-02')));
        self::assertNull($this->feeTerms->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000f1'));
        self::assertFalse($this->feeTerms->delete(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000f1'));
        self::assertTrue($this->feeTerms->delete(WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000f1'));
    }

    public function testTwoFeeTermsCannotShareADate(): void
    {
        $this->contracts->add($this->contract());
        $this->feeTerms->add($this->terms('00000000-0000-7000-8000-0000000000f1', '2026-01-01', '1', null, null));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->feeTerms->add($this->terms('00000000-0000-7000-8000-0000000000f2', '2026-01-01', '2', null, null));
    }

    public function testTheDatabaseRefusesAFeeRateAboveOneHundred(): void
    {
        $this->contracts->add($this->contract());

        $this->feeTerms->add($this->terms('00000000-0000-7000-8000-0000000000f1', '2026-01-01', '1', null, null));
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('Check violation');
        $this->connection->update('portfolio_life_insurance_fee_terms', ['entry_fee_rate_value' => '100.0001'], ['id' => '00000000-0000-7000-8000-0000000000f1']);
    }

    public function testRatesAreUniquePerSupportAndYearAndScopedToTheWorkspace(): void
    {
        $this->contracts->add($this->contract());
        $this->rates->add($this->rate('00000000-0000-7000-8000-0000000000f5', 2025, '2.50', '0.1'));
        $this->rates->add($this->rate('00000000-0000-7000-8000-0000000000f6', 2024, '-0.25', null));

        $listed = $this->rates->listForContract(WorkspaceFixture::own(), self::CONTRACT);
        self::assertSame([2025, 2024], array_map(static fn (EuroFundRate $rate): int => $rate->year, $listed));
        self::assertSame('2.50', $listed[0]->servedRate->toString());
        self::assertSame('0.1', $listed[0]->bonusRate?->toString());
        self::assertSame('-0.25', $listed[1]->servedRate->toString());
        self::assertTrue($this->rates->exists(WorkspaceFixture::own(), self::CONTRACT, self::FUND, 2025));
        self::assertFalse($this->rates->exists(WorkspaceFixture::own(), self::CONTRACT, self::FUND, 2023));
        self::assertSame(2, $this->rates->count(WorkspaceFixture::own(), self::CONTRACT));
        self::assertNull($this->rates->find(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000f5'));
        self::assertFalse($this->rates->delete(WorkspaceFixture::other(), '00000000-0000-7000-8000-0000000000f5'));
        self::assertTrue($this->rates->delete(WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000f5'));

        $this->rates->add($this->rate('00000000-0000-7000-8000-0000000000f7', 2025, '3', null));
        $this->expectException(UniqueConstraintViolationException::class);
        $this->rates->add($this->rate('00000000-0000-7000-8000-0000000000f8', 2025, '4', null));
    }

    public function testTheDatabaseRefusesAnIdentifierOnAnEuroFund(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessage('Check violation');
        $this->connection->update('portfolio_instruments', ['isin' => 'FR0000120271'], ['id' => self::FUND]);
    }

    private function contract(string $id = self::CONTRACT): LifeInsuranceContract
    {
        return LifeInsuranceContract::open(
            $id, WorkspaceFixture::own(), PortfolioFixture::ACCOUNT, 'Assureur Démo', null, 'Contrat Démo Vie',
            ContractNumberSuffix::fromNumber('AV-00012345'), new \DateTimeImmutable('2024-02-10'), null, ManagementMode::FREE,
            new \DateTimeImmutable('2026-10-05 10:00:00+00'), self::ACTOR, new \DateTimeImmutable('2026-10-05'),
        );
    }

    private function terms(string $id, string $from, ?string $entry, ?string $euro, ?string $fixed): FeeTerms
    {
        return new FeeTerms(
            $id, WorkspaceFixture::own(), self::CONTRACT, new \DateTimeImmutable($from),
            null === $entry ? null : DecimalValue::fromString($entry), null === $euro ? null : DecimalValue::fromString($euro),
            null, null, null === $fixed ? null : DecimalValue::fromString($fixed), null, new \DateTimeImmutable('2026-10-05 10:00:00+00'), self::ACTOR,
        );
    }

    private function rate(string $id, int $year, string $served, ?string $bonus): EuroFundRate
    {
        return new EuroFundRate(
            $id, WorkspaceFixture::own(), self::CONTRACT, self::FUND, $year, DecimalValue::fromString($served),
            null === $bonus ? null : DecimalValue::fromString($bonus), EuroFundRateBasis::NET_OF_MANAGEMENT_FEES, 'Relevé annuel',
            new \DateTimeImmutable('2026-10-05 10:00:00+00'), self::ACTOR,
        );
    }
}
