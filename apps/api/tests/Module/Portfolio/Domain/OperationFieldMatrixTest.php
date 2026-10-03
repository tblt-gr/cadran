<?php

declare(strict_types=1);

namespace App\Tests\Module\Portfolio\Domain;

use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\OperationFieldMatrix;
use App\Module\Portfolio\Domain\OperationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OperationFieldMatrixTest extends TestCase
{
    private const array ALL = [
        'instrument', 'quantity', 'unitPrice', 'grossAmount', 'fees', 'taxes', 'costBasis',
        'counterInstrument', 'counterQuantity', 'ratio', 'costAllocationRatio', 'linkedTransaction',
    ];

    /** @return iterable<string, array{OperationType, list<string>, list<string>}> */
    public static function matrix(): iterable
    {
        yield 'DEPOSIT' => [OperationType::DEPOSIT, ['grossAmount'], ['linkedTransaction']];
        yield 'WITHDRAWAL' => [OperationType::WITHDRAWAL, ['grossAmount'], ['linkedTransaction']];
        yield 'BUY' => [OperationType::BUY, ['instrument', 'quantity', 'unitPrice', 'grossAmount'], ['fees', 'taxes']];
        yield 'SELL' => [OperationType::SELL, ['instrument', 'quantity', 'unitPrice', 'grossAmount'], ['fees', 'taxes']];
        yield 'DIVIDEND' => [OperationType::DIVIDEND, ['instrument', 'grossAmount'], ['quantity', 'fees', 'taxes']];
        yield 'INTEREST' => [OperationType::INTEREST, ['grossAmount'], ['instrument', 'fees', 'taxes']];
        yield 'FEE' => [OperationType::FEE, ['fees'], ['instrument']];
        yield 'TAX' => [OperationType::TAX, ['taxes'], ['instrument']];
        yield 'SECURITY_TRANSFER_IN' => [OperationType::SECURITY_TRANSFER_IN, ['instrument', 'quantity'], ['costBasis']];
        yield 'SECURITY_TRANSFER_OUT' => [OperationType::SECURITY_TRANSFER_OUT, ['instrument', 'quantity'], []];
        yield 'SPLIT' => [OperationType::SPLIT, ['instrument', 'ratio'], []];
        yield 'MERGER' => [OperationType::MERGER, ['instrument', 'counterInstrument', 'counterQuantity'], ['grossAmount']];
        yield 'SPIN_OFF' => [OperationType::SPIN_OFF, ['instrument', 'counterInstrument', 'counterQuantity', 'costAllocationRatio'], []];
    }

    /**
     * @param list<string> $required
     * @param list<string> $optional
     */
    #[DataProvider('matrix')]
    public function testTheMatrixAcceptsRequiredAndOptionalFieldsOnly(OperationType $type, array $required, array $optional): void
    {
        OperationFieldMatrix::assertPresence($type, array_fill_keys($required, true));
        OperationFieldMatrix::assertPresence($type, array_fill_keys([...$required, ...$optional], true));

        foreach ($required as $missing) {
            $present = array_fill_keys(array_diff($required, [$missing]), true);
            $this->assertRefused($type, $present, 'operation_field_required', $missing);
        }
        foreach (array_diff(self::ALL, $required, $optional) as $forbidden) {
            $this->assertRefused($type, array_fill_keys([...$required, $forbidden], true), 'operation_field_not_allowed', $forbidden);
        }
    }

    /** @param array<string, bool> $present */
    private function assertRefused(OperationType $type, array $present, string $code, string $field): void
    {
        try {
            OperationFieldMatrix::assertPresence($type, $present);
            self::fail(sprintf('%s should refuse %s (%s).', $type->value, $field, $code));
        } catch (InvalidPortfolioOperation $exception) {
            self::assertSame($code, $exception->problemCode);
            self::assertSame($field, $exception->extensions['field'] ?? null);
        }
    }
}
