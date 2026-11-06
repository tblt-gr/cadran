<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Application\PortfolioOperationConflict;
use App\Module\Portfolio\Application\Takeover\InvalidPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\PortfolioTakeoverRefused;
use App\Module\Portfolio\Application\Takeover\PreviewPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\ReadPortfolioTakeover;
use App\Module\Portfolio\Application\Takeover\TakeoverError;
use App\Module\Portfolio\Application\Takeover\TakeoverInput;
use App\Module\Portfolio\Application\Takeover\TakeOverPortfolioPositions;
use App\Module\Portfolio\Application\ValuationConflict;
use App\Module\Portfolio\Domain\Cash\CashReplayFailed;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\Positions\PositionReplayFailed;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\UI\Http\TransactionHttpEnvelope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class PortfolioTakeoverController
{
    private const int MAX_BODY_BYTES = 131_072;
    private const string ACCOUNT_NOT_FOUND = 'portfolio_account_not_found';
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        private TranslatorInterface $translator,
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover', name: 'api_v1_portfolio_takeover_show', methods: ['GET'])]
    public function show(string $accountId, ReadPortfolioTakeover $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        try {
            $state = $read($accountId);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioTakeoverRepresentation::state($state));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover/preview', name: 'api_v1_portfolio_takeover_preview', methods: ['POST'])]
    public function preview(string $accountId, Request $request, PreviewPortfolioTakeover $preview, ReadPortfolioTakeover $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $result = $preview($accountId, $this->input($body, $accountId, $read));
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return self::json(PortfolioTakeoverRepresentation::preview($result));
    }

    #[Route('/api/v1/accounts/{accountId}/portfolio-takeover', name: 'api_v1_portfolio_takeover_create', methods: ['POST'])]
    public function create(string $accountId, Request $request, TakeOverPortfolioPositions $takeOver, ReadPortfolioTakeover $read): Response
    {
        if (!self::identifier($accountId)) {
            return $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND);
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $input = $this->input($body, $accountId, $read);
            $result = $this->idempotentExecution->execute(
                'portfolio.takeover.record',
                $this->envelope->idempotency($request, true),
                static function () use ($takeOver, $accountId, $input): IdempotentResponse {
                    $view = $takeOver($accountId, $input);

                    return new IdempotentResponse(PortfolioTakeoverRepresentation::takeover($view), Response::HTTP_CREATED, $view->takeover->id, [$view->takeover->takenOverOn->format('Y-m-d')]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (\Throwable $exception) {
            return $this->failure($exception);
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /**
     * @param array<mixed> $body
     *
     * @throws \UnexpectedValueException
     */
    private function input(array $body, string $accountId, ReadPortfolioTakeover $read): TakeoverInput
    {
        try {
            return TakeoverPayload::takeover($body);
        } catch (\UnexpectedValueException $exception) {
            // A foreign or unknown account answers 404 whatever the body looks like.
            $read($accountId);

            throw $exception;
        }
    }

    private function failure(\Throwable $exception): Response
    {
        return match (true) {
            $exception instanceof InvalidPortfolioTakeover => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_portfolio_takeover', ['errors' => PortfolioTakeoverRepresentation::errors($exception->errors)]),
            $exception instanceof \UnexpectedValueException => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_portfolio_takeover', ['errors' => PortfolioTakeoverRepresentation::errors([new TakeoverError(null, 'body', 'invalid_payload')])]),
            $exception instanceof PortfolioTakeoverRefused => $this->refusal($exception),
            $exception instanceof PositionReplayFailed => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode, $exception->extensions),
            $exception instanceof CashReplayFailed => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'cash_amount_too_large'),
            $exception instanceof InvalidPortfolioOperation => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode, $exception->extensions),
            $exception instanceof PortfolioAccountNotFound => $this->problem(Response::HTTP_NOT_FOUND, self::ACCOUNT_NOT_FOUND),
            // A limit is a refusal of the request, any other conflict a concurrent change.
            $exception instanceof PortfolioOperationConflict && PortfolioOperationConflict::LIMIT_REACHED === $exception->problemCode => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode),
            $exception instanceof ValuationConflict && ValuationConflict::LIMIT_REACHED === $exception->problemCode => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode),
            $exception instanceof PortfolioOperationConflict => $this->problem(Response::HTTP_CONFLICT, $exception->problemCode),
            $exception instanceof ValuationConflict => $this->problem(Response::HTTP_CONFLICT, $exception->problemCode),
            $exception instanceof WorkspaceAccessDenied => $this->problem(Response::HTTP_FORBIDDEN, 'portfolio_forbidden'),
            default => throw $exception,
        };
    }

    private function refusal(PortfolioTakeoverRefused $refusal): Response
    {
        return match ($refusal->reason) {
            PortfolioTakeoverBlockReason::NOT_SUPPORTED => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'portfolio_takeover_not_supported'),
            PortfolioTakeoverBlockReason::ALREADY_TAKEN_OVER => $this->problem(Response::HTTP_CONFLICT, 'portfolio_takeover_exists'),
            default => $this->problem(Response::HTTP_CONFLICT, 'portfolio_takeover_not_empty', ['blockingReason' => $refusal->reason->value]),
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

    /** @param array<string, mixed> $extensions */
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
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match(self::UUID_PATTERN, $value);
    }
}
