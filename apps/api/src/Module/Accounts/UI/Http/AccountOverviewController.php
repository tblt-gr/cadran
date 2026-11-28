<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountOverviewGrouping;
use App\Module\Accounts\Application\ListAccountOverviewRows;
use App\Module\Accounts\Application\MoveAccount;
use App\Module\Accounts\Application\ReadAccountOverview;
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

/**
 * Adapts HTTP to the unified account overview: group headings, the bounded
 * rows of one table and the move of one row inside its own group.
 */
final readonly class AccountOverviewController
{
    private const int MAX_CURSOR_LENGTH = 200;
    private const string UNGROUPED = 'ungrouped';

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    // The priority keeps this static segment ahead of the `/accounts/{id}` detail route.
    #[Route('/api/v1/accounts/overview', name: 'api_v1_accounts_overview', methods: ['GET'], priority: 10)]
    public function overview(Request $request, ReadAccountOverview $readOverview): Response
    {
        $includeClosed = $request->query->getString('includeClosed');
        $includeArchived = $request->query->getString('includeArchived');
        if (!QueryParameters::isFlagOrEmpty($includeClosed) || !QueryParameters::isFlagOrEmpty($includeArchived)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        }

        $grouping = $this->grouping($request);
        if ($grouping instanceof Response) {
            return $grouping;
        }

        try {
            $overview = $readOverview(
                '' === $request->query->getString('asOf') ? null : $request->query->getString('asOf'),
                'true' === $includeClosed,
                'true' === $includeArchived,
                $grouping,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::overview());
        }

        return $this->envelope->json(AccountOverviewRepresentation::overview($overview));
    }

    #[Route('/api/v1/accounts/overview/{groupKey}/accounts', name: 'api_v1_accounts_overview_rows', methods: ['GET'], priority: 10)]
    public function rows(string $groupKey, Request $request, ListAccountOverviewRows $listRows): Response
    {
        if (self::UNGROUPED !== $groupKey && !Identifier::isValid($groupKey)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }

        $includeClosed = $request->query->getString('includeClosed');
        $includeArchived = $request->query->getString('includeArchived');
        $cursor = $request->query->getString('cursor');
        if (!QueryParameters::isFlagOrEmpty($includeClosed) || !QueryParameters::isFlagOrEmpty($includeArchived)
            || strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        }

        $grouping = $this->grouping($request);
        if ($grouping instanceof Response) {
            return $grouping;
        }

        try {
            $page = $listRows(
                self::UNGROUPED === $groupKey ? null : $groupKey,
                '' === $cursor ? null : $cursor,
                '' === $request->query->getString('asOf') ? null : $request->query->getString('asOf'),
                'true' === $includeClosed,
                'true' === $includeArchived,
                $grouping,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::rows());
        }

        return $this->envelope->json(AccountOverviewRepresentation::rows($page));
    }

    /**
     * Moving is its own resource: it changes display order only, never the
     * account, so the account version is neither read nor bumped.
     */
    #[Route('/api/v1/accounts/{id}/move', name: 'api_v1_accounts_move', methods: ['POST'])]
    public function move(string $id, Request $request, MoveAccount $moveAccount): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_not_found');
        }
        $body = $this->envelope->body($request, BodyLimit::Tiny, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['groupId', 'beforeAccountId', 'orderVersion']);
            $groupId = $payload->nullableString('groupId');
            $beforeAccountId = $payload->nullableString('beforeAccountId');
            $version = $payload->integer('orderVersion');
            if ($version < 0 || (null !== $groupId && !Identifier::isValid($groupId))
                || (null !== $beforeAccountId && !Identifier::isValid($beforeAccountId))) {
                throw new MalformedPayload('The move identifiers or version are malformed.');
            }

            $moved = $moveAccount($id, $groupId, $beforeAccountId, $version);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountProblems::move());
        }

        return $this->envelope->json(AccountOverviewRepresentation::move($moved));
    }

    private function grouping(Request $request): AccountOverviewGrouping|Response
    {
        if (!$request->query->has('grouping')) {
            return AccountOverviewGrouping::PARENT;
        }

        $grouping = AccountOverviewGrouping::tryFrom($request->query->getString('grouping'));
        if (null !== $grouping) {
            return $grouping;
        }

        return $this->envelope->problem(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'api.problem.invalid_account_overview_query',
            extensions: ['errors' => [['field' => 'grouping', 'code' => 'invalid_choice']]],
        );
    }
}
