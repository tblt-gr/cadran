<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\CorrectAccountBalance;
use App\Module\Accounts\Application\CorrectAccountBalanceInput;
use App\Module\Accounts\Application\ListAccountBalances;
use App\Module\Accounts\Application\ReadAccountValuation;
use App\Module\Accounts\Application\ReadAccountValuationHistory;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\WithdrawAccountBalance;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adapts HTTP to dated balance snapshots. They live apart from the account
 * because a snapshot has its own identity, version and trail, and a client
 * must not record one by replaying an account form.
 */
final readonly class AccountBalanceController
{
    private const array CORRECT_FIELDS = ['amount', 'amountAssetCode', 'comment', 'version'];

    private const array RECORD_FIELDS = ['asOf', 'amount', 'amountAssetCode', 'comment', 'version'];

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/accounts/{id}/valuation', name: 'api_v1_accounts_valuation', methods: ['GET'])]
    public function valuation(string $id, Request $request, ReadAccountValuation $readValuation): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        try {
            $valuation = $readValuation($id, $request->query->getString('asOf') ?: null);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::valuation());
        }

        return $this->envelope->json(AccountValuationRepresentation::of($valuation));
    }

    #[Route('/api/v1/accounts/{id}/valuation-history', name: 'api_v1_accounts_valuation_history', methods: ['GET'])]
    public function history(string $id, Request $request, ReadAccountValuationHistory $readHistory): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }
        $query = $request->query->all();
        try {
            $history = $readHistory(
                $id,
                array_key_exists('from', $query) ? (is_string($query['from']) ? $query['from'] : '') : null,
                is_string($query['to'] ?? null) ? $query['to'] : '',
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::history());
        }

        return $this->envelope->json(AccountValuationHistoryRepresentation::of($history));
    }

    #[Route('/api/v1/accounts/{id}/balances', name: 'api_v1_accounts_balances_list', methods: ['GET'])]
    public function list(string $id, Request $request, ListAccountBalances $listBalances): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($pageSize)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_balance_query');
        }

        try {
            $snapshots = $listBalances(
                $id,
                '' === $page ? null : (int) $page,
                '' === $pageSize ? null : (int) $pageSize,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::list());
        }

        return $this->envelope->json(AccountBalanceSnapshotRepresentation::page($snapshots));
    }

    #[Route('/api/v1/accounts/{id}/balances', name: 'api_v1_accounts_balances_record', methods: ['POST'])]
    public function record(string $id, Request $request, RecordAccountBalance $recordBalance): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, self::RECORD_FIELDS);
            $snapshot = $recordBalance($id, new RecordAccountBalanceInput(
                asOf: $payload->string('asOf'),
                amount: $payload->raw('amount'),
                amountAssetCode: $payload->raw('amountAssetCode'),
                comment: $payload->nullableString('comment'),
                version: $payload->nullableInteger('version'),
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::record());
        }

        return $this->envelope->json(AccountBalanceSnapshotRepresentation::one($snapshot), Response::HTTP_CREATED);
    }

    /**
     * Amends the figure or comment of a manual snapshot in place: the same row,
     * the same date, no replacement. A withdrawn, imported or calculated
     * snapshot is refused.
     */
    #[Route(
        '/api/v1/accounts/{id}/balances/{snapshotId}',
        name: 'api_v1_accounts_balances_correct',
        methods: ['PATCH'],
    )]
    public function correct(
        string $id,
        string $snapshotId,
        Request $request,
        CorrectAccountBalance $correctBalance,
    ): Response {
        if (!Identifier::isValid($id) || !Identifier::isValid($snapshotId)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_balance_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, self::CORRECT_FIELDS);
            $snapshot = $correctBalance($id, $snapshotId, new CorrectAccountBalanceInput(
                amount: $payload->raw('amount'),
                amountAssetCode: $payload->raw('amountAssetCode'),
                comment: $payload->nullableString('comment'),
                version: $payload->integer('version'),
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::correct());
        }

        return $this->envelope->json(AccountBalanceSnapshotRepresentation::one($snapshot));
    }

    /**
     * Withdrawing keeps the row on the trail and stops it from anchoring the
     * balance. A calculated snapshot belongs to the portfolio valuation.
     */
    #[Route(
        '/api/v1/accounts/{id}/balances/{snapshotId}/withdraw',
        name: 'api_v1_accounts_balances_withdraw',
        methods: ['POST'],
    )]
    public function withdraw(
        string $id,
        string $snapshotId,
        Request $request,
        WithdrawAccountBalance $withdrawBalance,
    ): Response {
        if (!Identifier::isValid($id) || !Identifier::isValid($snapshotId)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_balance_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, ['version']);
            $snapshot = $withdrawBalance($id, $snapshotId, $payload->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountBalanceProblems::withdraw());
        }

        return $this->envelope->json(AccountBalanceSnapshotRepresentation::one($snapshot));
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }
}
