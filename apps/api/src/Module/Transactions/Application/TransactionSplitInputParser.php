<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Application\AmountInputParser;

/**
 * Parses the raw, already shape-checked split rows of a request body into
 * {@see TransactionSplitInput} value objects. Category resolution, sign,
 * asset and sum checks stay in {@see TransactionReferences}, which is the
 * step that needs the workspace and the transaction draft to answer them.
 */
final readonly class TransactionSplitInputParser
{
    public function __construct(private AmountInputParser $amountParser)
    {
    }

    /**
     * @param list<array{categoryId: string, amount: array{value: string, assetCode: string}, analyticAxes: ?list<string>, note: ?string}> $rows
     *
     * @return list<TransactionSplitInput>
     */
    public function parse(array $rows): array
    {
        return array_map(function (array $row): TransactionSplitInput {
            try {
                $axes = null === $row['analyticAxes'] ? null : array_map(
                    static fn (string $axis): AnalyticAxis => AnalyticAxis::from($axis),
                    $row['analyticAxes'],
                );
            } catch (\ValueError $exception) {
                throw new InvalidTransactionInput('A split analytic axis is not supported.', previous: $exception);
            }

            return new TransactionSplitInput(
                categoryId: $row['categoryId'],
                amount: ($this->amountParser)($row['amount'], '/splits/amount'),
                analyticAxes: $axes,
                note: $row['note'],
            );
        }, $rows);
    }
}
