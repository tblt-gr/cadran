<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\UI\Http;

use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditEvent;
use App\Module\Audit\Domain\AuditEventRepository;
use App\Module\Foundation\Infrastructure\Uuid\UuidV7Generator;
use App\Tests\Support\WorkspaceFixture;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;

final class LifeInsuranceSurrenderLifecycleTest extends LifeInsuranceApiTestCase
{
    private bool $failClosure = false;
    private bool $locked = false;

    protected function configureServices(): void
    {
        $auditRepository = self::getContainer()->get(AuditEventRepository::class);
        self::assertInstanceOf(AuditEventRepository::class, $auditRepository);
        $failingAudit = $this->createStub(AuditEventRepository::class);
        $failingAudit->method('append')->willReturnCallback(function (AuditEvent $event) use ($auditRepository): void {
            if (!$this->failClosure || 'life_insurance_contract.closed' !== $event->eventType) {
                $auditRepository->append($event);

                return;
            }
            $probe = DriverManager::getConnection($this->connection->getParams());
            try {
                $probe->executeQuery('SELECT id FROM portfolio_life_insurance_contracts WHERE workspace_id = ? AND account_id = ? FOR UPDATE NOWAIT', [WorkspaceFixture::OWN_WORKSPACE, self::LIFE]);
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
                $this->locked = true;
            } finally {
                $probe->close();
            }
            throw new \RuntimeException('Injected failure after closing the contract.');
        });
        self::getContainer()->set(RecordAuditEvent::class, new RecordAuditEvent($failingAudit, new UuidV7Generator()));
    }

    public function testFullSurrenderClosesAndVoidingReopensAtomically(): void
    {
        $this->record($this->premium());
        $input = $this->full();
        $input['legs'][1]['quantity'] = '41';
        $input['legs'][1]['amount'] = $this->eur('4305.00');
        $this->refused($input, 'position_would_go_negative');
        $remaining = $this->full();
        $remaining['legs'] = [$remaining['legs'][1]];
        $remaining['grossAmount'] = $this->eur('4200.00');
        $this->refused($remaining, 'full_surrender_positions_remaining');
        $this->refused([...$this->full(), 'grossAmount' => $this->eur('10199.99')], 'full_surrender_not_balanced');
        self::assertSame('10200.00', $this->decode()['expectedGrossAmount']);
        $this->refused([...$this->full(), 'grossAmount' => $this->eur('10200.01')], 'surrender_exceeds_available');
        $full = $this->record($this->full());
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract');
        self::assertSame('CLOSED', self::map($this->decode()['contract'])['status']);
        $this->refused([...$this->premium(), 'tradeDate' => '2026-07-01'], 'life_insurance_contract_closed');
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($full['id']).'/void', ['version' => 1, 'reason' => 'Undo full surrender']);
        self::assertResponseIsSuccessful();
        $this->send('GET', '/api/v1/accounts/'.self::LIFE.'/life-insurance-contract');
        self::assertSame('OPEN', self::map($this->decode()['contract'])['status']);
        self::assertNull(self::map($this->decode()['contract'])['closedOn']);
        $this->record([...$this->premium(), 'tradeDate' => '2026-07-01']);
        $this->refused($this->full(), 'operations_after_full_surrender');
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'life_insurance_contract.closed'"));
        self::assertSame(1, $this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'life_insurance_contract.reopened'"));
    }

    public function testFullSurrenderPreviewProvidesTheExpectedGrossWithoutWriting(): void
    {
        $this->record($this->premium());
        $this->send('POST', $this->operations(self::LIFE).'/preview', [...$this->full(), 'grossAmount' => $this->eur('1.00')]);
        self::assertResponseIsSuccessful();
        self::assertSame('10200.00', self::amountValue($this->decode(), 'expectedGrossAmount'));
        self::assertSame(1, $this->rows('portfolio_operations'));
        self::assertSame('OPEN', $this->connection->fetchOne('SELECT status FROM portfolio_life_insurance_contracts'));
    }

    public function testAFullSurrenderFailureRollsBackClosureParentAndLegsUnderTheContractLock(): void
    {
        $this->failClosure = true;
        try {
            $this->record($this->premium());
            $before = $this->connection->fetchAssociative('SELECT status, closed_on, version FROM portfolio_life_insurance_contracts');
            $this->send('POST', $this->operations(self::LIFE), $this->full());
            self::assertResponseStatusCodeSame(500);
            self::assertTrue($this->locked, 'The contract row stays locked until the operation and audit commit together.');
            self::assertSame($before, $this->connection->fetchAssociative('SELECT status, closed_on, version FROM portfolio_life_insurance_contracts'));
            self::assertSame(1, $this->rows('portfolio_operations'));
            self::assertSame(2, $this->rows('portfolio_operation_legs'));
            self::assertSame(0, $this->connection->fetchOne("SELECT count(*) FROM audit_events WHERE event_type = 'life_insurance_contract.closed'"));
        } finally {
            $this->failClosure = false;
        }
    }

    public function testCorrectionReopensAFullSurrenderAndKeepsItsLegHistory(): void
    {
        $this->record($this->premium());
        $full = $this->record($this->full());
        $this->send('POST', '/api/v1/portfolio-operations/'.self::string($full['id']).'/correct', ['version' => 1, 'operation' => $this->surrender()]);
        self::assertResponseStatusCodeSame(201, (string) $this->client->getResponse()->getContent());
        self::assertSame('1033.90', self::amountValue($this->decode(), 'netPaidAmount'));
        self::assertSame('OPEN', $this->connection->fetchOne('SELECT status FROM portfolio_life_insurance_contracts'));
        self::assertSame(5, $this->rows('portfolio_operation_legs'));
        $this->send('GET', '/api/v1/portfolio-operations/'.self::string($full['id']));
        self::assertSame('REPLACED', $this->decode()['voidKind']);
        self::assertCount(2, self::legs($this->decode()));
    }
}
