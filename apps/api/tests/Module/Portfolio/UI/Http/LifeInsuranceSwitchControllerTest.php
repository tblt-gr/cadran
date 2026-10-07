<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Infrastructure\Uuid\UuidV7Generator;
use App\Module\Portfolio\Domain\LifeInsurance\FeeNature;
use App\Module\Portfolio\Domain\Performance\ExternalFlow;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Infrastructure\Persistence\DbalPortfolioOperationRepository;
use App\Module\Portfolio\Infrastructure\Persistence\PortfolioOperationLegStore;
use App\Module\Reference\Application\AssetCatalog;
use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;

final class LifeInsuranceSwitchControllerTest extends LifeInsuranceApiTestCase
{
    private bool $failSecondLeg = false;

    protected function configureServices(): void
    {
        $this->client->catchExceptions(false);
        $count = 0;
        $uuid = $this->createStub(UuidGenerator::class);
        $uuid->method('generate')->willReturnCallback(function () use (&$count): string {
            if ($this->failSecondLeg && 2 === ++$count) {
                throw new \RuntimeException('Injected second-leg failure.');
            }

            return (new UuidV7Generator())->generate();
        });
        $assets = self::getContainer()->get(AssetCatalog::class);
        self::assertInstanceOf(AssetCatalog::class, $assets);
        self::getContainer()->set(PortfolioOperationRepository::class, new DbalPortfolioOperationRepository(
            $this->connection, new PortfolioOperationLegStore($this->connection, $uuid, $assets),
        ));
    }

    public function testReferenceAndImbalancedPreview(): void
    {
        $this->record($this->premium());
        $this->record($this->surrender());
        $input = $this->switchInput();
        $input['fees'] = $this->eur('10.01');
        $this->send('POST', $this->operations(self::LIFE).'/preview', $input);
        self::assertResponseIsSuccessful();
        self::assertSame('1989.99', self::amountValue($this->decode(), 'expectedIncomingAmount'));
        self::assertSame('0.01', self::amountValue($this->decode(), 'difference'));
        $this->refused($input, 'switch_not_balanced');
        self::assertSame('1989.99', $this->decode()['expectedIncomingAmount']);
        $switch = $this->record($this->switchInput());
        self::assertSame('2000.00', self::amountValue($switch, 'grossAmount'));
        self::assertSame('0', self::amountValue($switch, 'cashAmount'));
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-09-01');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('4915', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('4000', (string) $this->client->getResponse()->getContent());
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-cash?from=2026-09-01&to=2026-09-30');
        $totals = self::map($this->decode()['totals']);
        foreach (['deposits', 'withdrawals', 'dividendsGross', 'interestGross'] as $field) {
            self::assertSame('0', self::amountValue($totals, $field));
        }
        self::assertSame('10.00', self::amountValue($totals, 'fees'));
        self::assertSame('1990.00', self::amountValue($totals, 'supportsBought'));
        self::assertSame('2000.00', self::amountValue($totals, 'supportsSold'));
        $repository = self::getContainer()->get(PortfolioOperationRepository::class);
        self::assertInstanceOf(PortfolioOperationRepository::class, $repository);
        $operation = $repository->find(WorkspaceFixture::own(), self::string($switch['id']));
        self::assertNotNull($operation);
        self::assertNull(ExternalFlow::signedAmount($operation));
        self::assertFalse($operation->details->type->isExternalCashFlow());
        self::assertSame(FeeNature::SWITCH, FeeNature::of($operation));
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($switch['id']).'/void', ['version' => 1, 'reason' => 'Cancel entire switch']);
        self::assertResponseIsSuccessful();
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-09-01');
        self::assertStringContainsString('2925', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('6000', (string) $this->client->getResponse()->getContent());
    }

    public function testBothSidesRequiredDisjointAndServerGross(): void
    {
        $input = $this->switchInput();
        $input['legs'] = [$input['legs'][0]];
        $this->refused($input, 'operation_field_required');
        $input = $this->switchInput();
        $input['legs'][1] = ['direction' => 'IN', 'instrumentId' => self::FUND, 'amount' => $this->eur('1990.00')];
        $this->refused($input, 'switch_same_support');
        $this->refused([...$this->switchInput(), 'grossAmount' => $this->eur('2000.00')], 'operation_field_not_allowed');
        foreach (['grossAmount', 'taxes', 'costBasis', 'withheldIncomeTax', 'withheldSocialContributions'] as $field) {
            $this->refused([...$this->switchInput(), $field => $this->eur('0.00')], 'operation_field_not_allowed');
        }
        foreach (['quantity', 'unitPrice', 'counterQuantity', 'costAllocationRatio'] as $field) {
            $this->refused([...$this->switchInput(), $field => '1'], 'operation_field_not_allowed');
        }
        foreach (['instrumentId', 'counterInstrumentId'] as $field) {
            $this->refused([...$this->switchInput(), $field => self::ETF], 'operation_field_not_allowed');
        }
        $this->refused($this->switchInput(), 'position_would_go_negative');
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    public function testReplayCorrectionAndDatedInformativeFeeTerms(): void
    {
        $this->record($this->premium());
        $this->send('POST', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract/fee-terms', [
            'validFrom' => '2026-01-01', 'switchFeeRate' => '0.50', 'switchFeeFixed' => $this->eur('3.00'),
        ]);
        self::assertResponseStatusCodeSame(201);
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'insurance-switch-replay');
        $first = $this->record($this->switchInput());
        self::assertSame('0.50', $first['applicableSwitchFeeRate']);
        self::assertSame('3.00', self::amountValue($first, 'applicableSwitchFeeFixed'));
        self::assertEquals($first, $this->record($this->switchInput()));
        self::assertSame(2, $this->rows('portfolio_operations'));
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'insurance-switch-correct');
        $input = $this->switchInput();
        $input['fees'] = $this->eur('0.00');
        $input['legs'][1]['unitPrice'] = '100.00';
        $input['legs'][1]['amount'] = $this->eur('2000.00');
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($first['id']).'/correct', ['version' => 1, 'operation' => $input]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertCount(2, self::legs($this->decode()));
        self::assertSame(6, $this->rows('portfolio_operation_legs'));
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-09-01');
        self::assertStringContainsString('5900', (string) $this->client->getResponse()->getContent());
    }

    public function testForeignSupportsAndAccountAreIsolated(): void
    {
        $foreign = '00000000-0000-7000-8000-000000000b05';
        (new PortfolioFixture($this->connection))->instrument($foreign, WorkspaceFixture::OTHER_WORKSPACE, 'Private', 'DE0007164600');
        $input = $this->switchInput();
        $input['legs'][1]['instrumentId'] = $foreign;
        $this->refused($input, 'unknown_leg_instrument');
        $this->send('POST', $this->operations(self::SECURITIES), $this->switchInput());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('operation_type_not_allowed_for_account', $this->decode()['code']);
        $this->record($this->premium());
        $switch = $this->record($this->switchInput());
        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OTHER_OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        foreach (['', '/void', '/correct'] as $suffix) {
            $this->send('' === $suffix ? 'GET' : 'POST', '/api/v1/portfolio-operations/'.self::string($switch['id']).$suffix,
                '' === $suffix ? null : ('/void' === $suffix ? ['version' => 1, 'reason' => 'Forbidden'] : ['version' => 1, 'operation' => $this->switchInput()]));
            self::assertResponseStatusCodeSame(404);
        }
        $this->send('POST', $this->operations(self::LIFE).'/preview', $this->switchInput());
        self::assertResponseStatusCodeSame(404);
    }

    public function testSecondLegFailureRollsBackTheParentAndFirstLeg(): void
    {
        $this->record($this->premium());
        $this->failSecondLeg = true;
        $this->client->catchExceptions(false);
        try {
            $this->send('POST', $this->operations(self::LIFE), $this->switchInput());
            self::fail('The second leg must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Injected second-leg failure.', $exception->getMessage());
        } finally {
            $this->failSecondLeg = false;
        }
        self::assertSame(1, $this->rows('portfolio_operations'));
        self::assertSame(2, $this->rows('portfolio_operation_legs'));
    }

    public function testSwitchDoesNotChangeMonthlyBudgetTotalsOrTransfers(): void
    {
        $this->record($this->premium());
        $this->send('GET', '/api/v1/reports/monthly/recap?month=2026-09');
        self::assertResponseIsSuccessful();
        $before = $this->decode();
        $this->send('GET', '/api/v1/reports/monthly?month=2026-09');
        self::assertResponseIsSuccessful();
        $projection = $this->decode();
        $this->record($this->switchInput());
        $this->send('GET', '/api/v1/reports/monthly/recap?month=2026-09');
        self::assertResponseIsSuccessful();
        $after = $this->decode();
        self::assertSame($before['totals'], $after['totals']);
        self::assertSame($before['groups'], $after['groups']);
        self::assertNotSame($before['portfolioCosts'], $after['portfolioCosts']);
        $this->send('GET', '/api/v1/reports/monthly?month=2026-09');
        self::assertSame($projection, $this->decode());
        self::assertSame(0, $this->rows('transaction_transfers'));
    }

    /** @return array{type: string, tradeDate: string, fees: array{value: string, assetCode: string}, legs: list<array{direction: string, instrumentId: string, amount: array{value: string, assetCode: string}, quantity?: string, unitPrice?: string}>} */
    private function switchInput(): array
    {
        return ['type' => 'SWITCH', 'tradeDate' => '2026-09-01', 'fees' => $this->eur('10.00'), 'legs' => [
            ['direction' => 'OUT', 'instrumentId' => self::FUND, 'amount' => $this->eur('2000.00')],
            ['direction' => 'IN', 'instrumentId' => self::ETF, 'quantity' => '20', 'unitPrice' => '99.50', 'amount' => $this->eur('1990.00')],
        ]];
    }
}
