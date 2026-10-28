<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountGroupNotFound;
use App\Module\Accounts\Application\GroupCompositionTooLarge;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadAccountGroupComposition;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Adapts HTTP to the composition of one group between its sub-groups and accounts. */
final readonly class AccountGroupCompositionController
{
    public const string TYPE_TOO_LARGE = '/problems/composition-too-large';

    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/account-groups/{id}/composition', name: 'api_v1_account_groups_composition', methods: ['GET'])]
    public function composition(string $id, Request $request, ReadAccountGroupComposition $readComposition): Response
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $asOf = $request->query->getString('asOf');

        try {
            $composition = $readComposition($id, '' === $asOf ? null : $asOf);
        } catch (AccountGroupNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        } catch (InvalidAccountOverviewQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        } catch (GroupCompositionTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.composition_too_large', self::TYPE_TOO_LARGE);
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.net_worth_scope_too_large', AccountOverviewController::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_group_forbidden');
        }

        return new JsonResponse(
            AccountGroupCompositionRepresentation::composition($composition),
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
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
