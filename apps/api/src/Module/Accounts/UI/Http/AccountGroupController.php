<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupArchived;
use App\Module\Accounts\Application\AccountGroupConflict;
use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\ArchiveAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroupInput;
use App\Module\Accounts\Application\InvalidAccountGroupInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Accounts\Application\StaleAccountGroupVersion;
use App\Module\Accounts\Application\UpdateAccountGroup;
use App\Module\Accounts\Application\UpdateAccountGroupInput;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AccountGroupController
{
    public const string TYPE_LABEL_TAKEN = '/problems/account-group-label-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/account-group-archived';

    private const int MAX_BODY_BYTES = 16_384;
    private const array CREATE_FIELDS = ['label', 'parentId', 'sortOrder'];
    private const array UPDATE_FIELDS = ['label', 'parentId', 'sortOrder', 'version'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/account-groups', name: 'api_v1_account_groups_list', methods: ['GET'])]
    public function list(Request $request, ListAccountGroups $listAccountGroups): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        $includeArchived = $request->query->getString('includeArchived');
        $parentEligible = $request->query->getString('parentEligible');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage)
            || !in_array($includeArchived, ['', 'true', 'false'], true)
            || !in_array($parentEligible, ['', 'true', 'false'], true)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_group_query');
        }

        try {
            $groups = $listAccountGroups(
                'true' === $includeArchived,
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
                'true' === $parentEligible,
            );
        } catch (InvalidAccountGroupInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_group_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
        }

        return self::json(AccountGroupRepresentation::page($groups));
    }

    #[Route('/api/v1/account-groups', name: 'api_v1_account_groups_create', methods: ['POST'])]
    public function create(Request $request, CreateAccountGroup $createAccountGroup): Response
    {
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountGroupPayload::of($body, self::CREATE_FIELDS);
            $group = $createAccountGroup(new CreateAccountGroupInput(
                label: $payload->string('label'),
                parentId: $payload->nullableString('parentId'),
                sortOrder: $payload->integer('sortOrder'),
            ));
        } catch (InvalidAccountGroupInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_group');
        } catch (AccountGroupConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_group_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
        }

        return self::json(AccountGroupRepresentation::one($group), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/account-groups/{id}', name: 'api_v1_account_groups_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateAccountGroup $updateAccountGroup): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = AccountGroupPayload::of($body, self::UPDATE_FIELDS);
            $group = $updateAccountGroup($id, new UpdateAccountGroupInput(
                label: $payload->string('label'),
                parentId: $payload->nullableString('parentId'),
                sortOrder: $payload->integer('sortOrder'),
                version: $payload->integer('version'),
            ));
        } catch (AccountGroupArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_group_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountGroupInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_group');
        } catch (AccountGroupNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        } catch (StaleAccountGroupVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_group_stale_version', self::TYPE_STALE_VERSION);
        } catch (AccountGroupConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_group_label_taken', self::TYPE_LABEL_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
        }

        return self::json(AccountGroupRepresentation::one($group));
    }

    #[Route('/api/v1/account-groups/{id}/archive', name: 'api_v1_account_groups_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveAccountGroup $archiveAccountGroup): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $group = $archiveAccountGroup($id, AccountGroupPayload::of($body, ['version'])->integer('version'));
        } catch (AccountGroupArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.account_group_archived', self::TYPE_ARCHIVED);
        } catch (InvalidAccountGroupInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_group');
        } catch (AccountGroupNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        } catch (StaleAccountGroupVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_group_stale_version', self::TYPE_STALE_VERSION);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
        }

        return self::json(AccountGroupRepresentation::one($group));
    }

    /** @return array<mixed>|Response */
    private function readBody(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.account_group_payload_too_large');
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
