<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;

final class LifeInsuranceOperationsControllerTest extends LifeInsuranceApiTestCase
{
    public function testReferencePremiumAndSurrender(): void
    {
        $premium = $this->record($this->premium());
        self::assertSame('0.00', self::amountValue($premium, 'cashAmount'));
        self::assertCount(2, self::legs($premium));
        self::assertSame('6000.00', self::legs($premium)[0]['quantity']);
        self::assertSame('1', self::legs($premium)[0]['unitPrice']);
        self::assertNull($premium['netPaidAmount']);
        $surrender = $this->record($this->surrender());
        self::assertSame('0.00', self::amountValue($surrender, 'cashAmount'));
        self::assertSame('1033.90', self::amountValue($surrender, 'netPaidAmount'));
        self::assertSame('16.10', self::amountValue($surrender, 'taxes'));
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-positions?asOf=2026-06-10');
        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('2925', $body);
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-cash?from=2026-01-01&to=2026-06-30');
        self::assertResponseIsSuccessful();
        $cash = $this->decode();
        self::assertSame('10000.00', self::amountValue(self::map($cash['totals']), 'deposits'));
        self::assertSame('1033.90', self::amountValue(self::map($cash['totals']), 'withdrawals'));
        self::assertSame('100.00', self::amountValue(self::map($cash['totals']), 'fees'));
        self::assertSame('16.10', self::amountValue(self::map($cash['totals']), 'taxes'));
        self::assertSame('9900.00', self::amountValue(self::map($cash['totals']), 'supportsBought'));
        self::assertSame('1050.00', self::amountValue(self::map($cash['totals']), 'supportsSold'));
    }

    public function testLegValidationAndWorkspaceIsolation(): void
    {
        $portfolio = new PortfolioFixture($this->connection);
        $foreign = '00000000-0000-7000-8000-000000000b05';
        $portfolio->instrument($foreign, WorkspaceFixture::OTHER_WORKSPACE, 'Private', 'DE0007164600');
        foreach ([
            [['amount' => $this->eur('3900.01')], 'leg_amount_mismatch'],
            [['quantity' => '40.0000001'], 'quantity_precision_exceeded'],
            [['unitPrice' => '97.50001'], 'price_precision_exceeded'],
            [['direction' => 'OUT'], 'invalid_leg_direction'],
            [['instrumentId' => $foreign], 'unknown_leg_instrument'],
            [['instrumentId' => '00000000-0000-7000-8000-000000000fff'], 'unknown_leg_instrument'],
            [['quantity' => '0'], 'invalid_operation_quantity'],
            [['quantity' => '999999999999999999999999999'], 'quantity_precision_exceeded'],
        ] as [$change, $code]) {
            $input = $this->premium();
            $input['legs'][1] = [...$input['legs'][1], ...$change];
            $this->refused($input, $code);
        }
        $input = $this->premium();
        $input['legs'][0]['unitPrice'] = '1.01';
        $this->refused($input, 'euro_fund_price_fixed');
        $input = $this->premium();
        $input['legs'][] = $input['legs'][1];
        $this->refused($input, 'duplicate_leg_instrument');
        $input['legs'] = array_fill(0, 21, $input['legs'][0]);
        $this->refused($input, 'too_many_operation_legs');
        $input = $this->premium();
        $input['grossAmount'] = $this->eur('9000.00');
        $this->refused($input, 'premium_allocation_exceeds_net');
        $input = $this->surrender();
        $input['withheldIncomeTax'] = $this->eur('1051.00');
        $this->refused($input, 'invalid_surrender_amounts');
        $input = $this->surrender();
        $input['taxes'] = $this->eur('1.00');
        $this->refused($input, 'operation_field_not_allowed');
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_operation_legs'));
    }

    public function testPreviewLinkAndIdempotentReplay(): void
    {
        $portfolio = new PortfolioFixture($this->connection);
        $incoming = '00000000-0000-7000-8000-0000000000e1';
        $outgoing = '00000000-0000-7000-8000-0000000000e2';
        $portfolio->transferLeg($incoming, self::LIFE, '10000.00', '2026-01-15');
        $portfolio->transferLeg($outgoing, self::LIFE, '-1033.90', '2026-06-10');
        $this->send('POST', $this->operations(self::LIFE).'/preview', $this->premium());
        self::assertResponseIsSuccessful();
        self::assertSame('0.00', self::amountValue($this->decode(), 'cashAmount'));
        self::assertCount(2, self::legs($this->decode()));
        self::assertSame(0, $this->rows('portfolio_operations'));
        $input = [...$this->premium(), 'linkedTransactionId' => $incoming];
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'insurance-reference-premium');
        $first = $this->record($input);
        $second = $this->record($input);
        self::assertEquals($first, $second);
        self::assertSame(1, $this->rows('portfolio_operations'));
        $this->client->setServerParameter('HTTP_IDEMPOTENCY_KEY', 'insurance-reference-surrender');
        $this->record([...$this->surrender(), 'linkedTransactionId' => $outgoing]);
    }

    public function testClosedPeriodsRefuseBackdatedPremiumsAndSurrenders(): void
    {
        $this->closeMonthInDatabase($this->connection, 2026, 1);
        $this->send('POST', $this->operations(self::LIFE), $this->premium());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('/problems/period-closed', $this->decode()['type']);
        self::assertSame(0, $this->rows('portfolio_operations'));
        self::assertSame(0, $this->rows('portfolio_operation_legs'));
    }

    public function testLinkedTransferChecksAmountAndDayForBothDirections(): void
    {
        $portfolio = new PortfolioFixture($this->connection);
        $id = '00000000-0000-7000-8000-0000000000e1';
        $portfolio->transferLeg($id, self::LIFE, '9999.99', '2026-01-15');
        $this->refused([...$this->premium(), 'linkedTransactionId' => $id], 'linked_transfer_mismatch');
        $this->connection->update('transaction_transactions', ['amount_value' => '10000', 'booked_on' => '2026-01-16'], ['id' => $id]);
        $this->refused([...$this->premium(), 'linkedTransactionId' => $id], 'linked_transfer_mismatch');
        $this->record($this->premium());
        $out = '00000000-0000-7000-8000-0000000000e2';
        $portfolio->transferLeg($out, self::LIFE, '-1050.00', '2026-06-10');
        $this->refused([...$this->surrender(), 'linkedTransactionId' => $out], 'linked_transfer_mismatch');
        $this->connection->update('transaction_transactions', ['amount_value' => '-1033.90', 'booked_on' => '2026-06-11'], ['id' => $out]);
        $this->refused([...$this->surrender(), 'linkedTransactionId' => $out], 'linked_transfer_mismatch');
    }

    public function testAmountsAndAccountPolicyRejectWrongAssetsAndNonContractAccounts(): void
    {
        $input = $this->premium();
        $input['legs'][1]['amount'] = ['value' => '3900.00', 'assetCode' => 'USD'];
        $this->refused($input, 'operation_asset_mismatch');
        $input = $this->premium();
        $input['legs'][1]['amount'] = $this->eur('3900.000');
        $this->refused($input, 'invalid_operation_amount');
        $input = $this->surrender();
        $input['withheldIncomeTax'] = $this->eur('99999999999999999999999999.99');
        $input['withheldSocialContributions'] = $this->eur('99999999999999999999999999.99');
        $this->refused($input, 'invalid_operation_amount');
        $this->send('POST', $this->operations(self::SECURITIES), $this->premium());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('operation_type_not_allowed_for_account', $this->decode()['code']);
    }

    public function testExactPremiumLegsOmitAZeroRoundingResidualOnWriteAndPreview(): void
    {
        $premium = $this->record($this->premium());
        $this->send('GET', '/api/v1/portfolio-operations/'.self::string($premium['id']));
        foreach (self::legs($this->decode()) as $leg) {
            self::assertNull($leg['roundingResidual']);
        }
        $this->send('POST', $this->operations(self::LIFE).'/preview', $this->premium());
        self::assertResponseIsSuccessful();
        foreach (self::legs($this->decode()) as $leg) {
            self::assertNull($leg['roundingResidual']);
        }
    }

    public function testLegResidualSurvivesPersistenceAndProducesAnExplainedCashWarning(): void
    {
        $input = $this->premium();
        $input['legs'][1]['unitPrice'] = '97.5001';
        $premium = $this->record($input);
        $this->send('GET', '/api/v1/portfolio-operations/'.self::string($premium['id']));
        $leg = self::legs($this->decode())[1];
        self::assertSame('-0.0040', self::amountValue($leg, 'roundingResidual'));
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/portfolio-cash?from=2026-01-01&to=2026-01-31');
        self::assertResponseIsSuccessful();
        $warnings = $this->decode()['warnings'];
        self::assertIsArray($warnings);
        $codes = array_column($warnings, 'code');
        self::assertContains('ROUNDING_RESIDUALS', $codes);
    }

    public function testAnotherWorkspaceCannotReadPreviewCorrectOrVoidTheParentOrItsLegs(): void
    {
        $premium = $this->record($this->premium());
        $this->client->request('DELETE', '/api/v1/session', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(204);
        $this->send('POST', '/api/v1/session', ['email' => WorkspaceFixture::OTHER_OWNER_EMAIL, 'password' => WorkspaceFixture::OWNER_PASSWORD]);
        self::assertResponseStatusCodeSame(204);
        foreach ([
            ['GET', '/api/v1/portfolio-operations/'.self::string($premium['id']), null],
            ['POST', $this->operations(self::LIFE).'/preview', $this->premium()],
            ['POST', '/api/v1/portfolio-operations/'.self::string($premium['id']).'/correct', ['version' => 1, 'operation' => $this->premium()]],
            ['POST', '/api/v1/portfolio-operations/'.self::string($premium['id']).'/void', ['version' => 1, 'reason' => 'Forbidden']],
        ] as [$method, $path, $body]) {
            $this->send($method, $path, $body);
            self::assertResponseStatusCodeSame(404);
            self::assertStringNotContainsString('6000', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame('ACTIVE', $this->connection->fetchOne('SELECT state FROM portfolio_operations'));
        self::assertSame(2, $this->rows('portfolio_operation_legs'));
    }
}
