<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\Application\CashRangeTooDense;
use App\Module\Portfolio\Application\CashRangeTooLarge;
use App\Module\Portfolio\Application\InvalidCashQuery;
use App\Module\Portfolio\Application\InvalidHistoryRange;
use App\Module\Portfolio\Application\InvalidOperationQuery;
use App\Module\Portfolio\Application\InvalidPositionQuery;
use App\Module\Portfolio\Application\InvalidValuationQuery;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceConflict;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceNotFound;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioInstrumentNotFound;
use App\Module\Portfolio\Application\PortfolioOperationConflict;
use App\Module\Portfolio\Application\PortfolioOperationNotFound;
use App\Module\Portfolio\Application\StalePortfolioOperationOrder;
use App\Module\Portfolio\Application\StalePortfolioOperationVersion;
use App\Module\Portfolio\Application\Takeover\InvalidPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\PortfolioTakeoverRefused;
use App\Module\Portfolio\Application\Takeover\TakeoverError;
use App\Module\Portfolio\Application\ValuationConflict;
use App\Module\Portfolio\Application\ValuationNotFound;
use App\Module\Portfolio\Domain\Cash\CashReplayFailed;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which problem document each Portfolio exception becomes, one table per
 * controller because the same exception is not answered identically everywhere.
 * Every Portfolio problem carries its snake_case code as type, key and `code` member.
 */
final class PortfolioProblems
{
    private function __construct()
    {
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function valuation(): array
    {
        return [
            PositionReplayFailed::class => static fn (PositionReplayFailed $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            CashReplayFailed::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            \UnexpectedValueException::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_valuation_payload'),
            InvalidHistoryRange::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_history_range'),
            InvalidValuationQuery::class => self::coded(Response::HTTP_BAD_REQUEST, 'invalid_valuation_query'),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            ValuationNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_valuation_not_found'),
            ValuationConflict::class => static fn (ValuationConflict $e): ProblemRule => self::coded(Response::HTTP_CONFLICT, $e->problemCode),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function operation(): array
    {
        return [
            PositionReplayFailed::class => static fn (PositionReplayFailed $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(
                'operation_voided' === $e->problemCode ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->problemCode,
                $e->extensions,
            ),
            \UnexpectedValueException::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_operation_payload'),
            InvalidOperationQuery::class => self::coded(Response::HTTP_BAD_REQUEST, 'invalid_operation_query'),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            PortfolioOperationNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_operation_not_found'),
            StalePortfolioOperationVersion::class => self::coded(Response::HTTP_CONFLICT, 'stale_operation_version'),
            LinkedTransferTaken::class => self::coded(Response::HTTP_CONFLICT, 'linked_transfer_taken'),
            ValuationConflict::class => self::coded(Response::HTTP_CONFLICT, 'valuation_conflict'),
            PortfolioOperationConflict::class => static fn (PortfolioOperationConflict $e): ProblemRule => self::coded(Response::HTTP_CONFLICT, $e->problemCode),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function takeover(): array
    {
        return [
            InvalidPortfolioTakeover::class => static fn (InvalidPortfolioTakeover $e): ProblemRule => self::coded(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_portfolio_takeover',
                ['errors' => PortfolioTakeoverRepresentation::errors($e->errors)],
            ),
            \UnexpectedValueException::class => static fn (): ProblemRule => self::coded(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'invalid_portfolio_takeover',
                ['errors' => PortfolioTakeoverRepresentation::errors([new TakeoverError(null, 'body', 'invalid_payload')])],
            ),
            PortfolioTakeoverRefused::class => static fn (PortfolioTakeoverRefused $e): ProblemRule => match ($e->reason) {
                PortfolioTakeoverBlockReason::NOT_SUPPORTED => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'portfolio_takeover_not_supported'),
                PortfolioTakeoverBlockReason::ALREADY_TAKEN_OVER => self::coded(Response::HTTP_CONFLICT, 'portfolio_takeover_exists'),
                default => self::coded(Response::HTTP_CONFLICT, 'portfolio_takeover_not_empty', ['blockingReason' => $e->reason->value]),
            },
            PositionReplayFailed::class => static fn (PositionReplayFailed $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            CashReplayFailed::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            // A limit is a refusal of the request, any other conflict a concurrent change.
            PortfolioOperationConflict::class => static fn (PortfolioOperationConflict $e): ProblemRule => self::coded(
                PortfolioOperationConflict::LIMIT_REACHED === $e->problemCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT,
                $e->problemCode,
            ),
            ValuationConflict::class => static fn (ValuationConflict $e): ProblemRule => self::coded(
                ValuationConflict::LIMIT_REACHED === $e->problemCode ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT,
                $e->problemCode,
            ),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function lifeInsurance(): array
    {
        return [
            InvalidLifeInsurance::class => static fn (InvalidLifeInsurance $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode),
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode),
            \UnexpectedValueException::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_life_insurance_payload'),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            LifeInsuranceNotFound::class => static fn (LifeInsuranceNotFound $e): ProblemRule => self::coded(Response::HTTP_NOT_FOUND, $e->problemCode),
            LifeInsuranceConflict::class => static fn (LifeInsuranceConflict $e): ProblemRule => self::coded(Response::HTTP_CONFLICT, $e->problemCode),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function position(): array
    {
        return [
            PositionReplayFailed::class => static fn (PositionReplayFailed $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, $e->problemCode, $e->extensions),
            InvalidCashQuery::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_cash_query'),
            CashRangeTooLarge::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_large'),
            CashRangeTooDense::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_dense'),
            CashReplayFailed::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            InvalidPositionQuery::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_position_query'),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            PortfolioInstrumentNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_instrument_not_found'),
            StalePortfolioOperationOrder::class => self::coded(Response::HTTP_CONFLICT, 'stale_operation_order'),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @param array<string, mixed> $extensions */
    private static function coded(int $status, string $code, array $extensions = []): ProblemRule
    {
        return new ProblemRule(
            $status,
            'api.problem.'.$code,
            '/problems/'.str_replace('_', '-', $code),
            ['code' => $code, ...$extensions],
        );
    }
}
