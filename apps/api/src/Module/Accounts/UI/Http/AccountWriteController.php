<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ArchiveAccount;
use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\UpdateAccount;
use App\Module\Accounts\Application\UpdateAccountInput;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Write side of the account resource: create, update and archive. Field-level
 * validation lives in {@see JsonPayload}; reads stay in {@see AccountController}.
 */
final readonly class AccountWriteController
{
    private const array WRITE_FIELDS = [
        'label', 'kind', 'productCode', 'productModelId', 'institution', 'maskedIdentifier', 'valuationMode',
        'liquidityLevel', 'includeInNetWorth', 'includeInEmergencyFund', 'openedOn', 'closedOn',
        'primaryGroupId',
    ];

    public function __construct(
        private AccountHttp $http,
        private JsonRequestEnvelope $envelope,
        private ProblemMapper $problems,
    ) {
    }

    #[Route('/api/v1/accounts', name: 'api_v1_accounts_create', methods: ['POST'])]
    public function create(Request $request, CreateAccount $createAccount): Response
    {
        $body = $this->http->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, [...self::WRITE_FIELDS, 'assetCode']);
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
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::write(creating: true));
        }

        return $this->envelope->json(AccountRepresentation::one($account), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/accounts/{id}', name: 'api_v1_accounts_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateAccount $updateAccount): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->http->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, [...self::WRITE_FIELDS, 'version', ...(array_key_exists('isPrimary', $body) ? ['isPrimary'] : [])]);
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
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::write());
        }

        return $this->envelope->json(AccountRepresentation::one($account));
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
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $body = $this->http->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $account = $archiveAccount($id, JsonPayload::of($body, ['version'])->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::archive());
        }

        return $this->envelope->json(AccountRepresentation::one($account));
    }
}
