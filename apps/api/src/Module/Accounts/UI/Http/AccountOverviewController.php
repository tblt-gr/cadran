<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountNotFound;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\ListAccountOverviewRows;
use App\Module\Accounts\Application\MoveAccount;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadAccountOverview;
use App\Module\Accounts\Application\StaleAccountOrder;
use App\Module\Accounts\Domain\InvalidAccountMove;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to the unified account overview: group headings, the bounded
 * rows of one table and the move of one row inside its own group.
 */
final readonly class AccountOverviewController
{
    public const string TYPE_STALE_ORDER = '/problems/stale-account-order';
    public const string TYPE_SCOPE_TOO_LARGE = '/problems/net-worth-scope-too-large';

    private const int MAX_BODY_BYTES = 1_024;
    private const int MAX_CURSOR_LENGTH = 200;
    private const string UNGROUPED = 'ungrouped';
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    // The priority keeps this static segment ahead of the `/accounts/{id}` detail route.
    #[Route('/api/v1/accounts/overview', name: 'api_v1_accounts_overview', methods: ['GET'], priority: 10)]
    public function overview(Request $request, ReadAccountOverview $readOverview): Response
    {
        $includeClosed = $request->query->getString('includeClosed');
        $includeArchived = $request->query->getString('includeArchived');
        if (!self::flag($includeClosed) || !self::flag($includeArchived)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        }

        try {
            $overview = $readOverview(
                '' === $request->query->getString('asOf') ? null : $request->query->getString('asOf'),
                'true' === $includeClosed,
                'true' === $includeArchived,
            );
        } catch (InvalidAccountOverviewQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.net_worth_scope_too_large', self::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountOverviewRepresentation::overview($overview));
    }

    #[Route('/api/v1/accounts/overview/{groupKey}/accounts', name: 'api_v1_accounts_overview_rows', methods: ['GET'], priority: 10)]
    public function rows(string $groupKey, Request $request, ListAccountOverviewRows $listRows): Response
    {
        if (self::UNGROUPED !== $groupKey && 1 !== preg_match(self::UUID, $groupKey)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $includeClosed = $request->query->getString('includeClosed');
        $includeArchived = $request->query->getString('includeArchived');
        $cursor = $request->query->getString('cursor');
        if (!self::flag($includeClosed) || !self::flag($includeArchived)
            || strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        }

        try {
            $page = $listRows(
                self::UNGROUPED === $groupKey ? null : $groupKey,
                '' === $cursor ? null : $cursor,
                '' === $request->query->getString('asOf') ? null : $request->query->getString('asOf'),
                'true' === $includeClosed,
                'true' === $includeArchived,
            );
        } catch (InvalidAccountOverviewQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountOrder) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_order', self::TYPE_STALE_ORDER);
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.net_worth_scope_too_large', self::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountOverviewRepresentation::rows($page));
    }

    /**
     * Moving is its own resource: it changes display order only, never the
     * account, so the account version is neither read nor bumped.
     */
    #[Route('/api/v1/accounts/{id}/move', name: 'api_v1_accounts_move', methods: ['POST'])]
    public function move(string $id, Request $request, MoveAccount $moveAccount): Response
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.account_payload_too_large');
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $payload = AccountPayload::of($body, ['groupId', 'beforeAccountId', 'orderVersion']);
            $groupId = $payload->nullableString('groupId');
            $beforeAccountId = $payload->nullableString('beforeAccountId');
            $version = $payload->integer('orderVersion');
            if ($version < 0 || (null !== $groupId && 1 !== preg_match(self::UUID, $groupId))
                || (null !== $beforeAccountId && 1 !== preg_match(self::UUID, $beforeAccountId))) {
                throw new \UnexpectedValueException('The move identifiers or version are malformed.');
            }

            $moved = $moveAccount($id, $groupId, $beforeAccountId, $version);
        } catch (\UnexpectedValueException|InvalidAccountMove) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_account_move');
        } catch (AccountNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        } catch (StaleAccountOrder) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.account_stale_order', self::TYPE_STALE_ORDER);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return self::json(AccountOverviewRepresentation::move($moved));
    }

    private static function flag(string $value): bool
    {
        return in_array($value, ['', 'true', 'false'], true);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, Response::HTTP_OK, ['Cache-Control' => 'no-store']);
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
