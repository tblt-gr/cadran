<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ArchiveAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroup;
use App\Module\Accounts\Application\CreateAccountGroupInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Accounts\Application\MoveAccountGroup;
use App\Module\Accounts\Application\UpdateAccountGroup;
use App\Module\Accounts\Application\UpdateAccountGroupInput;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Foundation\UI\Http\QueryParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountGroupController
{
    private const int MAX_MOVE_SIBLINGS = 100;
    private const array CREATE_FIELDS = ['label', 'parentId'];
    private const array UPDATE_FIELDS = ['label', 'parentId', 'version'];
    /** Deprecated on write: accepted and ignored on create, applied on update only while the parent stays. */
    private const array DEPRECATED_FIELDS = ['sortOrder'];

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/account-groups', name: 'api_v1_account_groups_list', methods: ['GET'])]
    public function list(Request $request, ListAccountGroups $listAccountGroups): Response
    {
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        $includeArchived = $request->query->getString('includeArchived');
        $parentEligible = $request->query->getString('parentEligible');
        if (!QueryParameters::isUnsignedIntegerOrEmpty($page) || !QueryParameters::isUnsignedIntegerOrEmpty($pageSize)
            || !QueryParameters::isFlagOrEmpty($includeArchived)
            || !QueryParameters::isFlagOrEmpty($parentEligible)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_group_query');
        }

        try {
            $groups = $listAccountGroups(
                'true' === $includeArchived,
                QueryParameters::integerOrNull($page),
                QueryParameters::integerOrNull($pageSize),
                'true' === $parentEligible,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountGroupProblems::list());
        }

        return $this->envelope->json(AccountGroupRepresentation::page($groups));
    }

    #[Route('/api/v1/account-groups', name: 'api_v1_account_groups_create', methods: ['POST'])]
    public function create(Request $request, CreateAccountGroup $createAccountGroup): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_group_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        $color = AccountGroupColorPayload::of($body);
        if ([] !== $color->errors) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_account_group',
                extensions: ['errors' => $color->errors],
            );
        }

        try {
            $payload = JsonPayload::of($body, self::CREATE_FIELDS, ['color', 'colorMode', ...self::DEPRECATED_FIELDS]);
            $group = $createAccountGroup(new CreateAccountGroupInput(
                label: $payload->string('label'),
                parentId: $payload->nullableString('parentId'),
                color: $payload->nullableString('color'),
                colorMode: $color->mode,
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountGroupProblems::write());
        }

        return $this->envelope->json(AccountGroupRepresentation::one($group), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/account-groups/{id}', name: 'api_v1_account_groups_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateAccountGroup $updateAccountGroup): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_group_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        $color = AccountGroupColorPayload::of($body);
        if ([] !== $color->errors) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_account_group',
                extensions: ['errors' => $color->errors],
            );
        }

        try {
            $payload = JsonPayload::of($body, self::UPDATE_FIELDS, ['color', 'colorMode', ...self::DEPRECATED_FIELDS]);
            $group = $updateAccountGroup($id, new UpdateAccountGroupInput(
                label: $payload->string('label'),
                parentId: $payload->nullableString('parentId'),
                color: $payload->nullableString('color'),
                version: $payload->integer('version'),
                colorProvided: array_key_exists('color', $body),
                colorMode: $color->mode,
                sortOrder: array_key_exists('sortOrder', $body) ? $payload->integer('sortOrder') : null,
            ));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountGroupProblems::write());
        }

        return $this->envelope->json(AccountGroupRepresentation::one($group));
    }

    #[Route('/api/v1/account-groups/{id}/archive', name: 'api_v1_account_groups_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveAccountGroup $archiveAccountGroup): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_group_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $group = $archiveAccountGroup($id, JsonPayload::of($body, ['version'])->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountGroupProblems::archive());
        }

        return $this->envelope->json(AccountGroupRepresentation::one($group));
    }

    /**
     * Moving is its own resource: it changes display order only, never the
     * group, so the group version is neither read nor bumped.
     */
    #[Route('/api/v1/account-groups/{id}/move', name: 'api_v1_account_groups_move', methods: ['POST'])]
    public function move(string $id, Request $request, MoveAccountGroup $moveAccountGroup): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_group_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['beforeGroupId', 'expectedSiblingIds']);
            $beforeGroupId = $payload->nullableString('beforeGroupId');
            $expected = $body['expectedSiblingIds'] ?? null;
            if ((null !== $beforeGroupId && !Identifier::isValid($beforeGroupId)) || !self::siblingIds($expected)) {
                throw new MalformedPayload('The move identifiers are malformed.');
            }

            $moved = $moveAccountGroup($id, $beforeGroupId, $expected);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountGroupProblems::move());
        }

        return $this->envelope->json(['parentId' => $moved->parentId, 'siblingIds' => $moved->siblingIds, 'rank' => $moved->rank]);
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function siblingIds(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value) || [] === $value || count($value) > self::MAX_MOVE_SIBLINGS) {
            return false;
        }
        foreach ($value as $id) {
            if (!is_string($id) || !Identifier::isValid($id)) {
                return false;
            }
        }

        return count(array_unique($value)) === count($value);
    }
}
