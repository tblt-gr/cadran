<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemRule;
use App\Module\Portfolio\Application\CashRangeTooDense;
use App\Module\Portfolio\Application\CashRangeTooLarge;
use App\Module\Portfolio\Application\HoldingsTooLarge;
use App\Module\Portfolio\Application\InstrumentDuplicateCandidates;
use App\Module\Portfolio\Application\InstrumentIsinExists;
use App\Module\Portfolio\Application\InstrumentLimitReached;
use App\Module\Portfolio\Application\InstrumentNotFound;
use App\Module\Portfolio\Application\InvalidCashQuery;
use App\Module\Portfolio\Application\InvalidHistoryRange;
use App\Module\Portfolio\Application\InvalidHoldingsQuery;
use App\Module\Portfolio\Application\InvalidInstrumentInput;
use App\Module\Portfolio\Application\InvalidOperationQuery;
use App\Module\Portfolio\Application\InvalidPositionQuery;
use App\Module\Portfolio\Application\InvalidValuationQuery;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceConflict;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceNotFound;
use App\Module\Portfolio\Application\LinkedTransferTaken;
use App\Module\Portfolio\Application\Performance\PerformanceRangeTooDense;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioInstrumentNotFound;
use App\Module\Portfolio\Application\PortfolioOperationConflict;
use App\Module\Portfolio\Application\PortfolioOperationNotFound;
use App\Module\Portfolio\Application\StaleInstrumentVersion;
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
use App\Module\Transactions\Application\AccountTransactionScopeTooLarge;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\JsonResponse;
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
            InvalidPortfolioOperation::class => static fn (InvalidPortfolioOperation $e): ProblemRule => self::coded(
                'invalid_valuation_query' === $e->problemCode ? Response::HTTP_BAD_REQUEST : Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->problemCode,
                $e->extensions,
            ),
            MalformedPayload::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_valuation_payload'),
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
                match ($e->problemCode) {
                    'operation_voided' => Response::HTTP_CONFLICT,
                    'invalid_operation_query' => Response::HTTP_BAD_REQUEST,
                    default => Response::HTTP_UNPROCESSABLE_ENTITY,
                },
                $e->problemCode,
                $e->extensions,
            ),
            MalformedPayload::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_operation_payload'),
            // A repeated query parameter reaches the request reader as an array.
            BadRequestException::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_operation_payload'),
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
            MalformedPayload::class => static fn (): ProblemRule => self::coded(
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
            MalformedPayload::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_life_insurance_payload'),
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
            MalformedPayload::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_position_query'),
            InvalidCashQuery::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_cash_query'),
            CashRangeTooLarge::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_large'),
            CashRangeTooDense::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_dense'),
            PerformanceRangeTooDense::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_dense'),
            AccountTransactionScopeTooLarge::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_dense'),
            CashReplayFailed::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            InvalidPositionQuery::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_position_query'),
            PortfolioAccountNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            PortfolioInstrumentNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'portfolio_instrument_not_found'),
            StalePortfolioOperationOrder::class => self::coded(Response::HTTP_CONFLICT, 'stale_operation_order'),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule|\Closure> */
    public static function instrumentList(): array
    {
        return [
            InvalidInstrumentInput::class => static fn (InvalidInstrumentInput $e): ProblemRule => self::coded(Response::HTTP_BAD_REQUEST, $e->problemCode),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'instrument_forbidden'),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function instrumentRead(): array
    {
        return [
            InstrumentNotFound::class => self::coded(Response::HTTP_NOT_FOUND, 'instrument_not_found'),
            WorkspaceAccessDenied::class => self::coded(Response::HTTP_FORBIDDEN, 'instrument_forbidden'),
        ];
    }

    /**
     * Creating, updating and (de)activating an instrument.
     *
     * @return array<class-string<\Throwable>, ProblemRule|\Closure>
     */
    public static function instrumentWrite(): array
    {
        return [
            MalformedPayload::class => self::coded(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_instrument_payload'),
            // Asking for the state the instrument is already in is a conflict, any other refusal unprocessable.
            InvalidInstrumentInput::class => static fn (InvalidInstrumentInput $e): ProblemRule => self::coded(
                in_array($e->problemCode, ['instrument_already_active', 'instrument_already_inactive'], true)
                    ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->problemCode,
            ),
            StaleInstrumentVersion::class => self::coded(Response::HTTP_CONFLICT, 'stale_instrument_version'),
            InstrumentIsinExists::class => static fn (InstrumentIsinExists $e): ProblemRule => self::coded(
                Response::HTTP_CONFLICT,
                'instrument_isin_exists',
                ['existingId' => $e->existingId],
            ),
            InstrumentDuplicateCandidates::class => static fn (InstrumentDuplicateCandidates $e): ProblemRule => self::coded(
                Response::HTTP_CONFLICT,
                'instrument_duplicate_candidates',
                ['candidates' => array_map(InstrumentRepresentation::candidate(...), $e->candidates)],
            ),
            InstrumentLimitReached::class => self::coded(Response::HTTP_CONFLICT, 'instrument_limit_reached'),
            ...self::instrumentRead(),
        ];
    }

    /** @return array<class-string<\Throwable>, ProblemRule> */
    public static function holdings(): array
    {
        return [
            InvalidHoldingsQuery::class => new ProblemRule(
                Response::HTTP_BAD_REQUEST,
                'api.problem.invalid_holdings_query',
                extensions: ['code' => 'invalid_holdings_query'],
            ),
            HoldingsTooLarge::class => new ProblemRule(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.holdings_too_large',
                HoldingsController::TYPE_TOO_LARGE,
                ['code' => 'holdings_too_large'],
            ),
        ];
    }

    /**
     * The refusal of an unreadable body, carrying the portfolio `code` member like every row.
     *
     * @return \Closure(int, string): JsonResponse
     */
    public static function refusal(JsonRequestEnvelope $envelope): \Closure
    {
        return static function (int $status, string $translationKey) use ($envelope): JsonResponse {
            $rule = self::coded($status, substr($translationKey, strlen('api.problem.')));

            return $envelope->problem($rule->status, $rule->translationKey, $rule->type, $rule->extensions);
        };
    }

    /** @param array<string, mixed> $extensions */
    public static function coded(int $status, string $code, array $extensions = []): ProblemRule
    {
        return new ProblemRule(
            $status,
            'api.problem.'.$code,
            '/problems/'.str_replace('_', '-', $code),
            ['code' => $code, ...$extensions],
        );
    }
}
