<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

/**
 * Which fields each operation type carries: required (R), optional (O) or
 * forbidden (absent from both lists). A field that does not belong to the type
 * is refused rather than ignored, so a stored operation never holds a figure
 * the type cannot explain.
 */
final class OperationFieldMatrix
{
    public const array RATIO_FIELDS = ['numerator', 'denominator'];

    public const array FIELDS = [
        'instrument', 'quantity', 'unitPrice', 'grossAmount', 'fees', 'taxes', 'costBasis',
        'counterInstrument', 'counterQuantity', 'ratio', 'costAllocationRatio', 'linkedTransaction', 'legs', 'withheldIncomeTax', 'withheldSocialContributions',
    ];

    /** @return array{required: list<string>, optional: list<string>} */
    public static function rules(OperationType $type): array
    {
        return match ($type) {
            OperationType::SWITCH => ['required' => ['legs'], 'optional' => ['fees']],
            OperationType::PREMIUM => ['required' => ['grossAmount'], 'optional' => ['fees', 'legs', 'linkedTransaction']],
            OperationType::PARTIAL_SURRENDER, OperationType::FULL_SURRENDER => ['required' => ['grossAmount'], 'optional' => ['fees', 'legs', 'withheldIncomeTax', 'withheldSocialContributions', 'linkedTransaction']],
            OperationType::DEPOSIT, OperationType::WITHDRAWAL => ['required' => ['grossAmount'], 'optional' => ['linkedTransaction']],
            OperationType::BUY, OperationType::SELL => ['required' => ['instrument', 'quantity', 'unitPrice', 'grossAmount'], 'optional' => ['fees', 'taxes']],
            OperationType::DIVIDEND => ['required' => ['instrument', 'grossAmount'], 'optional' => ['quantity', 'fees', 'taxes']],
            OperationType::INTEREST => ['required' => ['grossAmount'], 'optional' => ['instrument', 'fees', 'taxes']],
            OperationType::FEE => ['required' => ['fees'], 'optional' => ['instrument']],
            OperationType::TAX => ['required' => ['taxes'], 'optional' => ['instrument']],
            OperationType::SECURITY_TRANSFER_IN => ['required' => ['instrument', 'quantity'], 'optional' => ['costBasis']],
            OperationType::SECURITY_TRANSFER_OUT => ['required' => ['instrument', 'quantity'], 'optional' => []],
            OperationType::SPLIT => ['required' => ['instrument', 'ratio'], 'optional' => []],
            OperationType::MERGER => ['required' => ['instrument', 'counterInstrument', 'counterQuantity'], 'optional' => ['grossAmount']],
            OperationType::SPIN_OFF => ['required' => ['instrument', 'counterInstrument', 'counterQuantity', 'costAllocationRatio'], 'optional' => []],
        };
    }

    /** @param array<string, bool> $present the matrix fields the input carries */
    public static function assertPresence(OperationType $type, array $present): void
    {
        $rules = self::rules($type);
        foreach (self::FIELDS as $field) {
            $has = $present[$field] ?? false;
            if ($has && !in_array($field, $rules['required'], true) && !in_array($field, $rules['optional'], true)) {
                throw InvalidPortfolioOperation::field('operation_field_not_allowed', $field);
            }
        }
        foreach ($rules['required'] as $field) {
            if (!($present[$field] ?? false)) {
                throw InvalidPortfolioOperation::field('operation_field_required', $field);
            }
        }
    }
}
