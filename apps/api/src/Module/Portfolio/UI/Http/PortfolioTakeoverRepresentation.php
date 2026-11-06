<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Portfolio\Application\Takeover\PortfolioTakeoverPreview;
use App\Module\Portfolio\Application\Takeover\PortfolioTakeoverState;
use App\Module\Portfolio\Application\Takeover\PortfolioTakeoverView;
use App\Module\Portfolio\Application\Takeover\TakeoverError;
use App\Module\Portfolio\Application\Takeover\TakeoverPreviewLine;

final class PortfolioTakeoverRepresentation
{
    /** @return array<string, mixed> */
    public static function state(PortfolioTakeoverState $state): array
    {
        return [
            'eligible' => $state->eligible,
            'reason' => $state->reason?->value,
            'defaultDate' => $state->defaultDate->format('Y-m-d'),
            'takeover' => null === $state->takeover ? null : self::takeover($state->takeover),
        ];
    }

    /** @return array<string, mixed> */
    public static function takeover(PortfolioTakeoverView $view): array
    {
        return [
            'id' => $view->takeover->id,
            'accountId' => $view->takeover->accountId,
            'takenOverOn' => $view->takeover->takenOverOn->format('Y-m-d'),
            'valuationId' => $view->takeover->valuationId,
            'operationIds' => $view->operationIds,
            'createdAt' => $view->takeover->createdAt->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public static function preview(PortfolioTakeoverPreview $preview): array
    {
        return [
            'lines' => array_map(static fn (TakeoverPreviewLine $line): array => [
                'instrumentId' => $line->instrumentId, 'value' => $line->value, 'averageUnitCost' => $line->averageUnitCost,
            ], $preview->lines),
            'securitiesValue' => $preview->securitiesValue,
            'cash' => $preview->cash,
            'totalValue' => $preview->totalValue,
            'errors' => self::errors($preview->errors),
        ];
    }

    /**
     * @param list<TakeoverError> $errors
     *
     * @return list<array{line: ?int, field: string, code: string}>
     */
    public static function errors(array $errors): array
    {
        return array_map(static fn (TakeoverError $error): array => ['line' => $error->line, 'field' => $error->field, 'code' => $error->code], $errors);
    }
}
