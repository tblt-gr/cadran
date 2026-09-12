<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Transactions\Application\CreateTransaction;
use App\Module\Transactions\Application\CreateTransactionInput;
use App\Module\Transactions\Application\DuplicateTransaction;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\ListTransactions;
use App\Module\Transactions\Application\ReadTransaction;
use App\Module\Transactions\Application\ReplaceTransactionSplits;
use App\Module\Transactions\Application\ReplaceTransactionSplitsInput;
use App\Module\Transactions\Application\StaleTransactionVersion;
use App\Module\Transactions\Application\TransactionConflict;
use App\Module\Transactions\Application\TransactionNotFound;
use App\Module\Transactions\Application\UpdateTransaction;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Module\Transactions\Application\VoidTransaction;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TransactionController
{
    private const array CREATE_FIELDS = [
        'accountId', 'amount', 'nature', 'state', 'bookedOn', 'valueOn', 'authorizedOn', 'rawLabel',
        'counterparty', 'note', 'paymentMethod', 'mcc', 'maskedCard', 'bankReference', 'categoryId', 'splits',
    ];
    private const array UPDATE_FIELDS = [...self::CREATE_FIELDS, 'version'];
    private const array LIST_QUERY_FIELDS = ['accountId', 'includeVoided', 'pageSize', 'cursor', 'categorization'];

    public function __construct(private TransactionHttpEnvelope $envelope)
    {
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_list', methods: ['GET'])]
    public function list(Request $request, ListTransactions $listTransactions): Response
    {
        if ([] !== array_diff(array_keys($request->query->all()), self::LIST_QUERY_FIELDS)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        $accountId = $request->query->has('accountId') ? $request->query->getString('accountId') : null;
        $includeVoided = $request->query->has('includeVoided') ? $request->query->getString('includeVoided') : null;
        $pageSize = $request->query->has('pageSize') ? $request->query->getString('pageSize') : null;
        $cursor = $request->query->has('cursor') ? $request->query->getString('cursor') : null;
        $categorization = $request->query->has('categorization') ? $request->query->getString('categorization') : null;
        if ((null !== $accountId && !$this->envelope->isIdentifier($accountId))
            || (null !== $includeVoided && !in_array($includeVoided, ['true', 'false'], true))
            || (null !== $pageSize && 1 !== preg_match('/^[0-9]{1,3}$/D', $pageSize))
            || (null !== $cursor && ('' === $cursor || strlen($cursor) > 128))
            || (null !== $categorization && 'NONE' !== $categorization)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        }

        try {
            $page = $listTransactions(
                $accountId,
                'true' === $includeVoided,
                null === $pageSize ? null : (int) $pageSize,
                $cursor,
                'NONE' === $categorization,
            );
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_transaction_query');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::page($page));
    }

    #[Route('/api/v1/transactions', name: 'api_v1_transactions_create', methods: ['POST'])]
    public function create(Request $request, CreateTransaction $createTransaction): Response
    {
        $body = $this->envelope->body($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $transaction = $createTransaction(self::createInput(TransactionPayload::of($body, self::CREATE_FIELDS)));
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

        return $this->envelope->json(TransactionRepresentation::one($transaction), Response::HTTP_CREATED);
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
    public function duplicate(string $id, DuplicateTransaction $duplicateTransaction): Response
    {
        if (!$this->envelope->isIdentifier($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }

        try {
            $transaction = $duplicateTransaction($id);
        } catch (InvalidSplitsInput $exception) {
            return $this->envelope->invalidSplitsProblem($exception);
        } catch (InvalidTransactionInput) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_transaction');
        } catch (TransactionNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        } catch (TransactionConflict) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.transaction_conflict', TransactionHttpEnvelope::TYPE_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.transaction_forbidden');
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction), Response::HTTP_CREATED);
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
