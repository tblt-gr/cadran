<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\CreateRefund;
use App\Module\Transactions\Application\CreateRefundInput;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\DuplicateTransaction;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidRefundRule;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ListTransactionsQuery;
use App\Module\Transactions\Application\ReadRefundable;
use App\Module\Transactions\Application\ReadTransaction;
use App\Module\Transactions\Application\Reconciliation\ReconcileTransaction;
use App\Module\Transactions\Application\RefundConflict;
use App\Module\Transactions\Application\ReplaceTransactionSplits;
use App\Module\Transactions\Application\ReplaceTransactionSplitsInput;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionBelongsToRefund;
use App\Module\Transactions\Application\TransactionBelongsToTransfer;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionCursorStale;
use App\Module\Transactions\Application\TransactionHasRefunds;
use App\Module\Transactions\Application\TransactionNotFound;
use App\Module\Transactions\Application\UpdateTransaction;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Module\Transactions\Application\VoidTransaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransactionController
{
    private const array CREATE_FIELDS = [
        'accountId', 'amount', 'nature', 'state', 'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel',
        'counterparty', 'note', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference', 'categoryId', 'splits', 'source',
        'sourceRef', 'reconcile',
    ];
    private const array UPDATE_FIELDS = [
        'accountId', 'amount', 'nature', 'state', 'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel',
        'counterparty', 'note', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference', 'categoryId', 'splits', 'version',
    ];
    private const array REFUND_FIELDS = ['accountId', 'amount', 'bookedOn', 'rawLabel', 'counterparty', 'note', 'splits'];
    private const array LIST_QUERY_FIELDS = [
        'from', 'to', 'accountId', 'state', 'nature', 'categoryId', 'includeDescendants', 'axis',
        'minAmount', 'maxAmount', 'assetCode', 'source', 'categorization', 'q', 'includeVoided',
        'pageSize', 'cursor',
    ];
    private const int MAX_MULTI_VALUE_FILTER = 20;
    private const string DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private IdempotentExecution $idempotentExecution,
    ) {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_list', methods: ['GET'])]
    public function list(Request $request, ListTransactions $listTransactions): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), self::LIST_QUERY_FIELDS)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        $from = $request->query->has('from') ? $request->query->getString('from') : null;
        $to = $request->query->has('to') ? $request->query->getString('to') : null;
        $includeVoided = $request->query->has('includeVoided') ? $request->query->getString('includeVoided') : null;
        $includeDescendants = $request->query->has('includeDescendants') ? $request->query->getString('includeDescendants') : null;
        $pageSize = $request->query->has('pageSize') ? $request->query->getString('pageSize') : null;
        $cursor = $request->query->has('cursor') ? $request->query->getString('cursor') : null;
        $categorization = $request->query->has('categorization') ? $request->query->getString('categorization') : null;
        $q = $request->query->has('q') ? $request->query->getString('q') : null;
        $minAmount = $request->query->has('minAmount') ? $request->query->getString('minAmount') : null;
        $maxAmount = $request->query->has('maxAmount') ? $request->query->getString('maxAmount') : null;
        $assetCode = $request->query->has('assetCode') ? $request->query->getString('assetCode') : null;

        $accountIds = self::repeatedQueryValues($request, 'accountId');
        $categoryIds = self::repeatedQueryValues($request, 'categoryId');
        $states = self::repeatedQueryValues($request, 'state');
        $natures = self::repeatedQueryValues($request, 'nature');
        $axes = self::repeatedQueryValues($request, 'axis');
        $sources = self::repeatedQueryValues($request, 'source');

        if (count($accountIds) > self::MAX_MULTI_VALUE_FILTER || count($categoryIds) > self::MAX_MULTI_VALUE_FILTER
            || count($states) > self::MAX_MULTI_VALUE_FILTER || count($natures) > self::MAX_MULTI_VALUE_FILTER
            || count($axes) > self::MAX_MULTI_VALUE_FILTER || count($sources) > self::MAX_MULTI_VALUE_FILTER
            || !self::allMatch($accountIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($categoryIds, $this->envelope->isIdentifier(...))
            || !self::allMatch($states, static fn (string $value): bool => null !== TransactionState::tryFrom($value))
            || !self::allMatch($natures, static fn (string $value): bool => null !== TransactionNature::tryFrom($value))
            || !self::allMatch($axes, static fn (string $value): bool => null !== AnalyticAxis::tryFrom($value))
            || !self::allMatch($sources, static fn (string $value): bool => null !== TransactionSource::tryFrom($value))
            || (null !== $from && 1 !== preg_match(self::DATE_PATTERN, $from))
            || (null !== $to && 1 !== preg_match(self::DATE_PATTERN, $to))
            || (null !== $includeVoided && !in_array($includeVoided, ['true', 'false'], true))
            || (null !== $includeDescendants && !in_array($includeDescendants, ['true', 'false'], true))
            || (null !== $pageSize && 1 !== preg_match('/^[0-9]{1,3}$/D', $pageSize))
            || (null !== $cursor && ('' === $cursor || strlen($cursor) > 200))
            || (null !== $categorization && !in_array($categorization, ['ANY', 'NONE'], true))
            || (null !== $q && mb_strlen($q, 'UTF-8') > ListTransactions::MAX_TEXT_QUERY_LENGTH)
            || (null !== $assetCode && '' === $assetCode)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        try {
            $page = $listTransactions(new ListTransactionsQuery(
                from: $from,
                to: $to,
                accountIds: $accountIds,
                states: $states,
                natures: $natures,
                categoryIds: $categoryIds,
                includeDescendants: 'true' === $includeDescendants,
                axes: $axes,
                minAmount: $minAmount,
                maxAmount: $maxAmount,
                assetCode: $assetCode,
                sources: $sources,
                categorizationNone: 'NONE' === $categorization,
                q: $q,
                includeVoided: 'true' === $includeVoided,
                pageSize: null === $pageSize ? null : (int) $pageSize,
                cursor: $cursor,
            ));
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        } catch (TransactionCursorStale) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_cursor_stale', '/problems/transactions.cursor_stale');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::page($page));
    }

    /**
     * Repeatable filters are declared in the OpenAPI contract as
     * `type: array` with `style: form, explode: true`, and the generated
     * client sends them as plain repeated keys (`?accountId=A&accountId=B`),
     * never bracketed. PHP's superglobal query parsing collapses repeated
     * non-bracket keys to the last value, and `$request->query` is built
     * from that superglobal — so reading it here would silently drop every
     * value but the last. Parsing the raw query string ourselves preserves
     * every repeated key, whether the client used brackets or not.
     *
     * @return list<string>
     */
    private static function repeatedQueryValues(Request $request, string $key): array
    {
        return self::rawQueryMultimap($request)[$key] ?? [];
    }

    /**
     * Groups every value of the raw, unparsed query string by key, treating
     * `key=`, `key[]=` and `key[0]=` as the same key. This is deliberately
     * independent from `$request->query`, which is derived from PHP's
     * superglobals and has already lost repeated non-bracket keys by the
     * time it reaches userland.
     *
     * @return array<string, list<string>>
     */
    private static function rawQueryMultimap(Request $request): array
    {
        $queryString = $request->server->get('QUERY_STRING');
        if (!is_string($queryString) || '' === $queryString) {
            return [];
        }

        $multimap = [];
        foreach (explode('&', $queryString) as $pair) {
            if ('' === $pair) {
                continue;
            }
            [$rawKey, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $key = rawurldecode(str_replace('+', ' ', $rawKey));
            $key = preg_replace('/\[[^\]]*]$/', '', $key) ?? $key;
            $value = rawurldecode(str_replace('+', ' ', $rawValue));
            $multimap[$key][] = $value;
        }

        return $multimap;
    }

    /** @param list<string> $values */
    private static function allMatch(array $values, \Closure $predicate): bool
    {
        foreach ($values as $value) {
            if (!$predicate($value)) {
                return false;
            }
        }

        return true;
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_create', methods: ['POST'])]
    public function create(Request $request, CreateTransaction $createTransaction): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            // Optional on the wire, always present here: the exact-field check
            // below refuses anything it was not told to expect.
            $body += ['source' => TransactionSource::MANUAL->value, 'sourceRef' => null, 'reconcile' => false];
            $payload = TransactionPayload::of($body, self::CREATE_FIELDS);
            $source = $payload->string('source');
            $result = $this->idempotentExecution->execute(
                'transaction.create',
                $this->envelope->idempotency($request, in_array($source, [TransactionSource::IMPORT->value, TransactionSource::PROVIDER->value], true)),
                function () use ($createTransaction, $payload): IdempotentResponse {
                    $transaction = $createTransaction(self::createInput($payload));

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_read', methods: ['GET'])]
    public function read(string $id, ReadTransaction $readTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        try {
            $transaction = $readTransaction($id);
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/refundable', name: 'api_v1_transactions_refundable', methods: ['GET'])]
    public function refundable(string $id, ReadRefundable $readRefundable): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        try {
            $view = $readRefundable($id);
        } catch (InvalidRefundRule $exception) {
            return $this->envelope->invalidRefundRuleProblem($exception);
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(RefundRepresentation::refundable($view));
    }

    #[Route('/api/v1/transactions/{id}/refunds', name: 'api_v1_transactions_create_refund', methods: ['POST'])]
    public function createRefund(string $id, Request $request, CreateRefund $createRefund): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $body += ['splits' => null];
            $payload = TransactionPayload::of($body, self::REFUND_FIELDS);
            $input = new CreateRefundInput(
                $payload->identifier('accountId'), $payload->amount('amount'), $payload->string('bookedOn'),
                $payload->string('rawLabel'), $payload->nullableString('counterparty'), $payload->nullableString('note'),
                $payload->nullableSplitRows('splits'),
            );
            $result = $this->idempotentExecution->execute(
                'refund.create',
                $this->envelope->idempotency($request, false),
                function () use ($createRefund, $id, $input): IdempotentResponse {
                    $created = $createRefund($id, $input);
                    $refund = $created->refund;

                    return new IdempotentResponse(
                        TransactionRepresentation::one($refund), Response::HTTP_CREATED, $refund->id,
                        [$refund->bookedOn, $created->originalBookedOn],
                    );
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidRefundRule $exception) {
            return $this->envelope->invalidRefundRuleProblem($exception);
        } catch (RefundConflict $exception) {
            return $this->envelope->refundConflictProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    #[Route('/api/v1/transactions/{id}', name: 'api_v1_transactions_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateTransaction $updateTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, self::UPDATE_FIELDS);
            $transaction = $updateTransaction($id, self::updateInput($payload));
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToTransfer $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_transfer',
                ['transferId' => $exception->transferId], '/problems/transaction-belongs-to-transfer',
            );
        } catch (TransactionBelongsToRefund $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_refund',
                ['originalId' => $exception->originalTransactionId], '/problems/transaction.belongs_to_refund',
            );
        } catch (TransactionHasRefunds) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_has_refunds', '/problems/transaction.has_refunds');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (StaleTransactionVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/splits', name: 'api_v1_transactions_replace_splits', methods: ['PUT'])]
    public function replaceSplits(string $id, Request $request, ReplaceTransactionSplits $replaceTransactionSplits): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version', 'splits']);
            $input = new ReplaceTransactionSplitsInput(
                splits: $payload->splitRows('splits'),
                version: $payload->integer('version'),
            );
            $transaction = $replaceTransactionSplits($id, $input);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToTransfer $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_transfer',
                ['transferId' => $exception->transferId], '/problems/transaction-belongs-to-transfer',
            );
        } catch (TransactionBelongsToRefund $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_refund',
                ['originalId' => $exception->originalTransactionId], '/problems/transaction.belongs_to_refund',
            );
        } catch (TransactionHasRefunds) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_has_refunds', '/problems/transaction.has_refunds');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (StaleTransactionVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/void', name: 'api_v1_transactions_void', methods: ['POST'])]
    public function void(string $id, Request $request, VoidTransaction $voidTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version']);
            $transaction = $voidTransaction($id, $payload->integer('version'));
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToTransfer $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_transfer',
                ['transferId' => $exception->transferId], '/problems/transaction-belongs-to-transfer',
            );
        } catch (TransactionHasRefunds) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_has_refunds', '/problems/transaction.has_refunds');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (StaleTransactionVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/reconcile', name: 'api_v1_transactions_reconcile', methods: ['POST'])]
    public function reconcile(string $id, Request $request, ReconcileTransaction $reconcileTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = TransactionPayload::of($body, ['version', 'matchedTransactionId']);
            $transaction = $reconcileTransaction(
                $id,
                $payload->integer('version'),
                $payload->nullableIdentifier('matchedTransactionId'),
            );
        } catch (InvalidTransactionInput|\UnexpectedValueException) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (StaleTransactionVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_stale_version', TransactionHttpEnvelope::TYPE_STALE_VERSION);
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }

    #[Route('/api/v1/transactions/{id}/duplicate', name: 'api_v1_transactions_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, DuplicateTransaction $duplicateTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        $hasBody = '' !== $request->getContent();
        $body = [];
        if ($hasBody) {
            $body = $this->envelope->body($request);
            if ($body instanceof Response) {
                return $body;
            }
        }
        try {
            $result = $this->idempotentExecution->execute(
                'transaction.duplicate',
                $this->envelope->idempotency($request, false, true),
                function () use ($duplicateTransaction, $id, $body, $hasBody, $request): IdempotentResponse {
                    if ($hasBody && !$this->envelope->hasJsonObjectBody($request)) {
                        throw new InvalidTransactionInput();
                    }
                    TransactionPayload::of($body, []);
                    $transaction = $duplicateTransaction($id);

                    return new IdempotentResponse(TransactionRepresentation::one($transaction), Response::HTTP_CREATED, $transaction->id, [$transaction->bookedOn]);
                },
            );
        } catch (InvalidIdempotencyKey|IdempotencyConflict $exception) {
            return $this->envelope->idempotencyProblem($exception);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionBelongsToRefund $exception) {
            return $this->envelope->problemWithExtensions(
                Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.transaction_belongs_to_refund',
                ['originalId' => $exception->originalTransactionId], '/problems/transaction.belongs_to_refund',
            );
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    private static function createInput(TransactionPayload $payload): CreateTransactionInput
    {
        return new CreateTransactionInput(
            accountId: $payload->identifier('accountId'),
            amount: $payload->amount('amount'),
            nature: $payload->string('nature'),
            state: $payload->string('state'),
            bookedOn: $payload->string('bookedOn'),
            valueOn: $payload->nullableString('valueOn'),
            authorizedOn: $payload->nullableString('authorizedOn'),
            rawLabel: $payload->string('rawLabel'),
            counterparty: $payload->nullableString('counterparty'),
            note: $payload->nullableString('note'),
            paymentMethod: $payload->nullableString('paymentMethod'),
            mcc: $payload->nullableString('mcc'),
            maskedCard: $payload->nullableString('maskedCard'),
            bankReference: $payload->nullableString('bankReference'),
            categoryId: $payload->nullableIdentifier('categoryId'),
            splits: $payload->nullableSplitRows('splits'),
            source: $payload->string('source'),
            sourceRef: $payload->nullableString('sourceRef'),
            reconcile: $payload->boolean('reconcile'),
        );
    }

    private static function updateInput(TransactionPayload $payload): UpdateTransactionInput
    {
        return new UpdateTransactionInput(
            accountId: $payload->identifier('accountId'),
            amount: $payload->amount('amount'),
            nature: $payload->string('nature'),
            state: $payload->string('state'),
            bookedOn: $payload->string('bookedOn'),
            valueOn: $payload->nullableString('valueOn'),
            authorizedOn: $payload->nullableString('authorizedOn'),
            rawLabel: $payload->string('rawLabel'),
            counterparty: $payload->nullableString('counterparty'),
            note: $payload->nullableString('note'),
            paymentMethod: $payload->nullableString('paymentMethod'),
            mcc: $payload->nullableString('mcc'),
            maskedCard: $payload->nullableString('maskedCard'),
            bankReference: $payload->nullableString('bankReference'),
            categoryId: $payload->nullableIdentifier('categoryId'),
            splits: $payload->nullableSplitRows('splits'),
            version: $payload->integer('version'),
        );
    }
}
