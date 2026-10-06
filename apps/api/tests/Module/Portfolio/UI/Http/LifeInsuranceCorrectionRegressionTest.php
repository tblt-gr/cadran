<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Tests\Support\PortfolioFixture;
use App\Tests\Support\WorkspaceFixture;

final class LifeInsuranceCorrectionRegressionTest extends LifeInsuranceApiTestCase
{
    public function testSameDayPremiumNoteCorrectionStaysBeforeTheFullSurrender(): void
    {
        $premium = $this->record($this->cashPremium());
        $full = $this->record($this->cashSurrender('FULL_SURRENDER', '100.00'));
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($premium['id']).'/correct', [
            'version' => 1, 'operation' => [...$this->cashPremium(), 'note' => 'Corrected note'],
        ]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame(1, $this->decode()['sequence']);
        self::assertSame(2, $full['sequence']);
        self::assertSame('CLOSED', $this->connection->fetchOne('SELECT status FROM portfolio_life_insurance_contracts'));
        self::assertSame(2, $this->connection->fetchOne("SELECT count(*) FROM portfolio_operations WHERE state = 'ACTIVE'"));
    }

    public function testMovingTheCorrectionToAnotherDateUsesThatDaysNextSequence(): void
    {
        $original = $this->record($this->cashPremium());
        $moved = [...$this->cashPremium(), 'tradeDate' => '2026-01-16'];
        $this->record($moved);
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($original['id']).'/correct', [
            'version' => 1, 'operation' => $moved,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->decode()['sequence']);
        self::assertSame('2026-01-16', $this->decode()['tradeDate']);
    }

    public function testCorrectionPreviewExcludesOriginalAndReusesItsLinkedTransferWithoutWriting(): void
    {
        $this->record($this->cashPremium());
        $link = '00000000-0000-7000-8000-0000000000e9';
        (new PortfolioFixture($this->connection))->transferLeg($link, self::LIFE, '-60.00', '2026-01-15');
        $input = [...$this->cashSurrender('PARTIAL_SURRENDER', '60.00'), 'linkedTransactionId' => $link];
        $original = $this->record($input);
        $before = $this->snapshot();
        $this->preview(self::string($original['id']), $input);
        self::assertResponseIsSuccessful();
        self::assertSame('60.00', self::amountValue($this->decode(), 'netPaidAmount'));
        self::assertSame($before, $this->snapshot());
        $this->send('POST', $this->operations(self::LIFE).'/preview', $input);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('linked_transfer_taken', $this->decode()['code']);
    }

    public function testFullSurrenderCorrectionPreviewSimulatesReopeningAndChangedDateWithoutWriting(): void
    {
        $this->record($this->cashPremium());
        $full = $this->record($this->cashSurrender('FULL_SURRENDER', '100.00'));
        $before = $this->snapshot();
        $this->preview(self::string($full['id']), [
            ...$this->cashSurrender('PARTIAL_SURRENDER', '60.00'), 'tradeDate' => '2026-01-16',
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->snapshot());
        $this->preview(self::string($full['id']), $this->cashSurrender('FULL_SURRENDER', '100.00'));
        self::assertResponseIsSuccessful();
        self::assertSame('100.00', self::amountValue($this->decode(), 'expectedGrossAmount'));
        self::assertSame($before, $this->snapshot());
    }

    public function testPreviewSameDayPremiumReplacementKeepsItsOriginalSequence(): void
    {
        $premium = $this->record($this->cashPremium());
        $this->record($this->cashSurrender('FULL_SURRENDER', '100.00'));
        $before = $this->snapshot();
        $this->preview(self::string($premium['id']), [...$this->cashPremium(), 'note' => 'Preview']);
        self::assertResponseIsSuccessful();
        self::assertNull($this->decode()['expectedGrossAmount']);
        self::assertSame($before, $this->snapshot());
    }

    public function testPremium101CorrectionStrictlyValidatesTheRetainedFullSurrender(): void
    {
        $this->assertRetainedFullSurrenderCorrectionRefused('101.00', 'full_surrender_not_balanced');
    }

    public function testPremium99CorrectionStrictlyValidatesTheRetainedFullSurrender(): void
    {
        $this->assertRetainedFullSurrenderCorrectionRefused('99.00', 'surrender_exceeds_available');
    }

    public function testOnlyTheCandidateFullSurrenderCanPreviewAnUnbalancedGross(): void
    {
        $this->record($this->cashPremium());
        $before = $this->snapshot();
        foreach (['1.00', '101.00'] as $gross) {
            $this->send('POST', $this->operations(self::LIFE).'/preview', $this->cashSurrender('FULL_SURRENDER', $gross));
            self::assertResponseIsSuccessful();
            self::assertSame('100.00', self::amountValue($this->decode(), 'expectedGrossAmount'));
            self::assertSame($before, $this->snapshot());
        }
        $full = $this->record($this->cashSurrender('FULL_SURRENDER', '100.00'));
        $before = $this->snapshot();
        $this->preview(self::string($full['id']), $this->cashSurrender('FULL_SURRENDER', '101.00'));
        self::assertResponseIsSuccessful();
        self::assertSame('100.00', self::amountValue($this->decode(), 'expectedGrossAmount'));
        self::assertSame($before, $this->snapshot());
    }

    private function assertRetainedFullSurrenderCorrectionRefused(string $gross, string $code): void
    {
        $premium = $this->record($this->cashPremium());
        $this->record($this->cashSurrender('FULL_SURRENDER', '100.00'));
        $before = $this->snapshot();
        $input = [...$this->cashPremium(), 'grossAmount' => $this->eur($gross)];
        $id = self::string($premium['id']);
        $this->preview($id, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->decode()['code']);
        if ('full_surrender_not_balanced' === $code) {
            self::assertSame($gross, $this->decode()['expectedGrossAmount']);
        }
        self::assertSame($before, $this->snapshot());
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/correct', ['version' => 1, 'operation' => $input]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->decode()['code']);
        self::assertSame($before, $this->snapshot());
    }

    public function testPreviewRejectsMissingOtherAccountForeignAndVoidedTargets(): void
    {
        $original = $this->record($this->cashPremium());
        $id = self::string($original['id']);
        foreach (['00000000-0000-7000-8000-00000000ffff', 'invalid'] as $missing) {
            $this->preview($missing, $this->cashPremium());
            self::assertResponseStatusCodeSame(404);
        }
        $this->send('POST', $this->operations(self::SECURITIES).'/preview?correctingOperationId='.$id, [
            'type' => 'DEPOSIT', 'tradeDate' => '2026-01-15', 'grossAmount' => $this->eur('100.00'),
        ]);
        self::assertResponseStatusCodeSame(404);
        $foreignAccount = '00000000-0000-7000-8000-000000000f99';
        (new PortfolioFixture($this->connection))->account($foreignAccount, WorkspaceFixture::OTHER_WORKSPACE);
        $row = $this->connection->fetchAssociative('SELECT * FROM portfolio_operations WHERE id = ?', [$id]);
        self::assertIsArray($row);
        $foreignId = '00000000-0000-7000-8000-000000000f98';
        $this->connection->insert('portfolio_operations', [...$row, 'id' => $foreignId,
            'account_id' => $foreignAccount, 'workspace_id' => WorkspaceFixture::OTHER_WORKSPACE,
            'created_by' => WorkspaceFixture::OTHER_OWNER_ID]);
        $this->preview($foreignId, $this->cashPremium());
        self::assertResponseStatusCodeSame(404);
        $this->send('POST', '/api/v1/portfolio-operations/'.$id.'/void', ['version' => 1, 'reason' => 'Preview inactive target']);
        self::assertResponseIsSuccessful();
        $this->preview($id, $this->cashPremium());
        self::assertResponseStatusCodeSame(409);
        self::assertSame('operation_voided', $this->decode()['code']);
    }

    public function testEveryFalsyLegIdentifierReturnsASafeRowError(): void
    {
        foreach (['', '0'] as $id) {
            $input = $this->premium();
            $input['legs'][0]['instrumentId'] = $id;
            $this->refused($input, 'unknown_leg_instrument');
            self::assertSame(1, $this->decode()['legIndex']);
        }
        self::assertSame(0, $this->rows('portfolio_operations'));
    }

    /** @return array<string, mixed> */
    private function cashPremium(): array
    {
        return ['type' => 'PREMIUM', 'tradeDate' => '2026-01-15', 'grossAmount' => $this->eur('100.00')];
    }

    /** @return array<string, mixed> */
    private function cashSurrender(string $type, string $gross): array
    {
        return ['type' => $type, 'tradeDate' => '2026-01-15', 'grossAmount' => $this->eur($gross)];
    }

    /** @param array<string, mixed> $input */
    private function preview(string $id, array $input): void
    {
        $this->send('POST', $this->operations(self::LIFE).'/preview?correctingOperationId='.$id, $input);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function snapshot(): array
    {
        return [
            'operations' => $this->connection->fetchAllAssociative('SELECT * FROM portfolio_operations ORDER BY id'),
            'contracts' => $this->connection->fetchAllAssociative('SELECT * FROM portfolio_life_insurance_contracts ORDER BY id'),
            'audit' => $this->connection->fetchAllAssociative('SELECT * FROM audit_events ORDER BY id'),
            'accounts' => $this->connection->fetchAllAssociative('SELECT * FROM account_financial_accounts ORDER BY id'),
        ];
    }
}
