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
use App\Module\Accounts\Application\StaleAccountVersion;
use App\Module\Accounts\Application\UpdateAccount;
use App\Module\Accounts\Application\UpdateAccountInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\Identifier;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Write side of the account resource: create, update and archive. Field-level
 * validation lives in {@see AccountPayload}; reads stay in {@see AccountController}.
 */
final readonly class AccountWriteController
{
    /**
     * A label already taken and a stale version are both 409, but they ask the
     * caller for opposite things: pick another label, or reload and reapply.
     * The problem type is what lets a client tell them apart.
     */
    public const string TYPE_LABEL_TAKEN = '/problems/account-label-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-archived';

    private const array WRITE_FIELDS = [
        'label', 'kind', 'productCode', 'productModelId', 'institution', 'maskedIdentifier', 'valuationMode',
        'liquidityLevel', 'includeInNetWorth', 'includeInEmergencyFund', 'openedOn', 'closedOn',
        'primaryGroupId',
    ];

    public function __construct(private AccountHttp $http)
    {
    }

    #[Route('/api/v1/accounts', name: 'api_v1_accounts_create', methods: ['POST'])]
    public function create(Request $request, CreateAccount $createAccount): Response
    {
        $body = $this->http->readBody($request);
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
                primaryGroupId: $payload->nullableString('primaryGroupId'),
            ));
        } catch (AccountArchived) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (StaleAccountVersion) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->http->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return $this->http->json(AccountRepresentation::one($account), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/accounts/{id}', name: 'api_v1_accounts_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateAccount $updateAccount): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->http->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->http->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountPayload::of($body, [...self::WRITE_FIELDS, 'version', ...(array_key_exists('isPrimary', $body) ? ['isPrimary'] : [])]);
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
                primaryGroupId: $payload->nullableString('primaryGroupId'),
                isPrimary: array_key_exists('isPrimary', $body) ? $payload->boolean('isPrimary') : null,
            ));
        } catch (AccountArchived) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (AccountNotFound) {
            return $this->http->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountVersion) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->http->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return $this->http->json(AccountRepresentation::one($account));
    }

    /**
     * Archiving is its own resource rather than a field of the update body: it
     * is the one edit an archived account still accepts refusing, and a client
     * must not reach it by replaying a stale form.
     */
    #[Route('/api/v1/accounts/{id}/archive', name: 'api_v1_accounts_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveAccount $archiveAccount): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->http->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->http->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $account = $archiveAccount($id, AccountPayload::of($body, ['version'])->integer('version'));
        } catch (AccountArchived) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountInput|\UnexpectedValueException) {
            return $this->http->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account');
        } catch (AccountNotFound) {
            return $this->http->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountVersion) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountConflict) {
            return $this->http->problem(Response::HTTP_CONFLICT, 'api.problem.account_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->http->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return $this->http->json(AccountRepresentation::one($account));
    }
}
