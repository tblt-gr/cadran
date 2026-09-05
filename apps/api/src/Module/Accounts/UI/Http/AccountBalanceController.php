<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountBalanceConflict;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountBalanceInput;
use App\Module\Accounts\Application\ListAccountBalances;
use App\Module\Accounts\Application\ReadAccountValuation;
use App\Module\Accounts\Application\RecordAccountBalance;
use App\Module\Accounts\Application\RecordAccountBalanceInput;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to dated balance snapshots. They live apart from the account
 * because a snapshot has its own identity, version and trail, and a client
 * must not record one by replaying an account form.
 */
final readonly class AccountBalanceController
{
    public const string TYPE_SNAPSHOT_CONFLICT = '/problems/account-balance-conflict';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-archived';

    private const int MAX_BODY_BYTES = 16_384;
    private const array RECORD_FIELDS = ['asOf', 'amount', 'amountAssetCode', 'comment', 'version'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts/{id}/valuation', name: 'api_v1_accounts_valuation', methods: ['GET'])]
    public function valuation(string $id, Request $request, ReadAccountValuation $readValuation): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        try {
            $valuation = $readValuation($id, $request->query->getString('asOf') ?: null);
        } catch (InvalidAccountBalanceInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_valuation_query');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountValuationRepresentation::of($valuation));
    }

    #[Route('/api/v1/accounts/{id}/balances', name: 'api_v1_accounts_balances_list', methods: ['GET'])]
    public function list(string $id, Request $request, ListAccountBalances $listBalances): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_balance_query');
        }

        try {
            $snapshots = $listBalances(
                $id,
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
            );
        } catch (InvalidAccountBalanceInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_balance_query');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountBalanceSnapshotRepresentation::page($snapshots));
    }

    #[Route('/api/v1/accounts/{id}/balances', name: 'api_v1_accounts_balances_record', methods: ['POST'])]
    public function record(string $id, Request $request, RecordAccountBalance $recordBalance): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, self::RECORD_FIELDS);
            $snapshot = $recordBalance($id, new RecordAccountBalanceInput(
                asOf: $payload->string('asOf'),
                amount: $payload->string('amount'),
                amountAssetCode: $payload->string('amountAssetCode'),
                comment: $payload->nullableString('comment'),
                version: $payload->nullableInteger('version'),
            ));
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountBalanceInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_balance');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_balance_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountBalanceConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_balance_conflict', self::TYPE_SNAPSHOT_CONFLICT);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountBalanceSnapshotRepresentation::one($snapshot), Response::HTTP_CREATED);
    }

    /** @return array<mixed>|Response */
    private function readBody(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.account_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
        );
    }
}
