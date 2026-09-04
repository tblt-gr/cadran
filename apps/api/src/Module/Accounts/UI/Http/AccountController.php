<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountArchived;
use App\Module\Accounts\Application\AccountConflict;
use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\ArchiveAccount;
use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\InvalidAccountInput;
use App\Module\Accounts\Application\ListAccounts;
use App\Module\Accounts\Application\ReadAccountRules;
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Application\UpdateAccount;
use App\Module\Accounts\Application\UpdateAccountInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to the account use cases. Field-level validation lives in
 * {@see AccountPayload} and the wire shape in {@see AccountRepresentation};
 * what remains here is the envelope, the routing and the status mapping.
 */
final readonly class AccountController
{
    /**
     * A label already taken and a stale version are both 409, but they ask the
     * caller for opposite things: pick another label, or reload and reapply.
     * The problem type is what lets a client tell them apart.
     */
    public const string TYPE_LABEL_TAKEN = '/problems/account-label-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-archived';

    private const int MAX_BODY_BYTES = 16_384;
    private const array WRITE_FIELDS = [
        'label', 'kind', 'productCode', 'productModelId', 'institution', 'maskedIdentifier', 'valuationMode',
        'liquidityLevel', 'includeInNetWorth', 'includeInEmergencyFund', 'openedOn', 'closedOn',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/accounts', name: 'api_v1_accounts_list', methods: ['GET'])]
    public function list(Request $request, ListAccounts $listAccounts): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        $includeArchived = $request->query->getString('includeArchived');
        $includeClosed = $request->query->getString('includeClosed');
        $kind = $request->query->getString('kind');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage)
            || !self::flag($includeArchived) || !self::flag($includeClosed)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_query');
        }

        try {
            $accounts = $listAccounts(
                'true' === $includeArchived,
                'true' === $includeClosed,
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
                '' === $kind ? null : $kind,
            );
        } catch (InvalidAccountInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRepresentation::page($accounts));
    }

    #[Route('/api/v1/accounts', name: 'api_v1_accounts_create', methods: ['POST'])]
    public function create(Request $request, CreateAccount $createAccount): Response
    {
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, [...self::WRITE_FIELDS, 'assetCode']);
            $account = $createAccount(new CreateAccountInput(
                label: $payload->string('label'),
                assetCode: $payload->string('assetCode'),
                kind: $payload->string('kind'),
                productCode: $payload->nullableString('productCode'),
                productModelId: $payload->nullableString('productModelId'),
                institution: $payload->nullableString('institution'),
                maskedIdentifier: $payload->nullableString('maskedIdentifier'),
                valuationMode: $payload->string('valuationMode'),
                liquidityLevel: $payload->string('liquidityLevel'),
                includeInNetWorth: $payload->boolean('includeInNetWorth'),
                includeInEmergencyFund: $payload->boolean('includeInEmergencyFund'),
                openedOn: $payload->string('openedOn'),
                closedOn: $payload->nullableString('closedOn'),
            ));
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (StaleAccountVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRepresentation::one($account), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/accounts/{id}', name: 'api_v1_accounts_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateAccount $updateAccount): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, [...self::WRITE_FIELDS, 'version']);
            $account = $updateAccount($id, new UpdateAccountInput(
                label: $payload->string('label'),
                kind: $payload->string('kind'),
                productCode: $payload->nullableString('productCode'),
                productModelId: $payload->nullableString('productModelId'),
                institution: $payload->nullableString('institution'),
                maskedIdentifier: $payload->nullableString('maskedIdentifier'),
                valuationMode: $payload->string('valuationMode'),
                liquidityLevel: $payload->string('liquidityLevel'),
                includeInNetWorth: $payload->boolean('includeInNetWorth'),
                includeInEmergencyFund: $payload->boolean('includeInEmergencyFund'),
                openedOn: $payload->string('openedOn'),
                closedOn: $payload->nullableString('closedOn'),
                version: $payload->integer('version'),
            ));
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRepresentation::one($account));
    }

    /**
     * The rules in force for the account, resolved on a business date. They
     * are a resource of their own because they are not part of the account:
     * nothing here is stored on it, and the same account answers differently
     * for two different dates.
     */
    #[Route('/api/v1/accounts/{id}/rules', name: 'api_v1_accounts_rules', methods: ['GET'])]
    public function rules(string $id, Request $request, ReadAccountRules $readAccountRules): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $asOf = $request->query->getString('asOf');

        try {
            $rules = $readAccountRules($id, '' === $asOf ? null : $asOf);
        } catch (InvalidAccountInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_rules_query');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRulesRepresentation::of($rules));
    }

    /**
     * Archiving is its own resource rather than a field of the update body: it
     * is the one edit an archived account still accepts refusing, and a client
     * must not reach it by replaying a stale form.
     */
    #[Route('/api/v1/accounts/{id}/archive', name: 'api_v1_accounts_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveAccount $archiveAccount): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $account = $archiveAccount($id, AccountPayload::of($body, ['version'])->integer('version'));
        } catch (AccountArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountRepresentation::one($account));
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
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

    private static function flag(string $value): bool
    {
        return in_array($value, ['', 'true', 'false'], true);
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
