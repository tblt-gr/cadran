<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\CashRangeTooDense;
use App\Module\Portfolio\Application\CashRangeTooLarge;
use App\Module\Portfolio\Application\InvalidCashQuery;
use App\Module\Portfolio\Application\InvalidPositionQuery;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioInstrumentNotFound;
use App\Module\Portfolio\Application\ReadPortfolioCash;
use App\Module\Portfolio\Application\ReadPortfolioLots;
use App\Module\Portfolio\Application\ReadPortfolioPositions;
use App\Module\Portfolio\Application\ReorderPortfolioOperations;
use App\Module\Portfolio\Application\StalePortfolioOperationOrder;
use App\Module\Portfolio\Domain\Cash\CashReplayFailed;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PortfolioPositionController
{
    /** 10 000 UUIDs plus JSON structure, bounded with room for insignificant whitespace. */
    private const int MAX_BODY_BYTES = 500_000;
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-positions', name: 'api_v1_portfolio_positions_list', methods: ['GET'])]
    public function positions(string $accountId, Request $request, ReadPortfolioPositions $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found');
        }
        try {
            $asOf = self::scalar($request->query->all(), 'asOf');
            $includeClosedLiteral = self::scalar($request->query->all(), 'includeClosed');
            $includeClosed = match ($includeClosedLiteral) {
                null, 'false' => false,
                'true' => true,
                default => throw new InvalidPositionQuery('includeClosed is a boolean.'),
            };
            $view = $read($accountId, $asOf, $includeClosed);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(['asOf' => $view->asOf, 'items' => $view->items]);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-cash', name: 'api_v1_portfolio_cash', methods: ['GET'])]
    public function cash(string $accountId, Request $request, ReadPortfolioCash $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found');
        }
        try {
            $query = $request->query->all();
            $view = $read($accountId, self::cashScalar($query, 'from'), self::cashScalar($query, 'to'));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioCashRepresentation::of($view));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-positions/{instrumentId}/lots', name: 'api_v1_portfolio_position_lots', methods: ['GET'])]
    public function lots(string $accountId, string $instrumentId, Request $request, ReadPortfolioLots $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found');
        }
        if (!self::identifier($instrumentId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_instrument_not_found');
        }
        try {
            $view = $read($accountId, $instrumentId, self::scalar($request->query->all(), 'asOf'));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json([
            'asOf' => $view->asOf,
            'instrument' => $view->instrument,
            'openLots' => $view->openLots,
            'movements' => $view->movements,
            'fifoCostBasis' => $view->fifoCostBasis,
        ]);
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-operations/order', name: 'api_v1_portfolio_operations_order', methods: ['PUT'])]
    public function reorder(string $accountId, Request $request, ReorderPortfolioOperations $reorder): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found');
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $fields = OperationPayload::shape($body, ['tradeDate', 'operationIds']);
            if (!is_string($fields['tradeDate']) || !is_array($fields['operationIds']) || !array_is_list($fields['operationIds'])) {
                throw new InvalidPositionQuery('The operation order payload is invalid.');
            }
            $ids = [];
            foreach ($fields['operationIds'] as $id) {
                if (!is_string($id)) {
                    throw new InvalidPositionQuery('Operation identifiers are strings.');
                }
                $ids[] = $id;
            }
            $view = $reorder($accountId, $fields['tradeDate'], $ids);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(['tradeDate' => $view->tradeDate, 'operationIds' => $view->operationIds]);
    }

    private function failure(\Throwable $exception): Response
    {
        return match (true) {
            $exception instanceof PositionReplayFailed => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode, $exception->extensions),
            $exception instanceof InvalidPortfolioOperation => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode, $exception->extensions),
            $exception instanceof InvalidCashQuery => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_cash_query'),
            $exception instanceof CashRangeTooLarge => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_large'),
            $exception instanceof CashRangeTooDense => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_range_too_dense'),
            $exception instanceof CashReplayFailed => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            $exception instanceof InvalidPositionQuery => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_position_query'),
            $exception instanceof PortfolioAccountNotFound => $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_account_not_found'),
            $exception instanceof PortfolioInstrumentNotFound => $this->problem(Response::HTTP_NOT_FOUND, 'portfolio_instrument_not_found'),
            $exception instanceof StalePortfolioOperationOrder => $this->problem(Response::HTTP_CONFLICT, 'stale_operation_order'),
            $exception instanceof WorkspaceAccessDenied => $this->problem(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
            default => throw $exception,
        };
    }

    /** @return array<mixed>|Response */
    private function body(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'portfolio_payload_too_large');
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_request');
        }
    }

    /** @param array<string, string> $extensions */
    private function problem(int $status, string $code, array $extensions = []): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans('api.problem.'.$code.'.title'),
            $this->translator->trans('api.problem.'.$code.'.detail'),
            '/problems/'.str_replace('_', '-', $code),
            extensions: ['code' => $code, ...$extensions],
        );
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $value);
    }

    /** @param array<string, mixed> $query */
    private static function cashScalar(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidCashQuery('A query parameter is a single value.');
        }

        return $value;
    }

    /** @param array<string, mixed> $query */
    private static function scalar(array $query, string $key): ?string
    {
        $value = $query[$key] ?? null;
        if (null !== $value && !is_string($value)) {
            throw new InvalidPositionQuery('A query parameter is a single value.');
        }

        return $value;
    }
}
