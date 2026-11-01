<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AccountOverviewCompositionTooLarge;
use App\Module\Accounts\Application\InvalidAccountOverviewQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadAccountOverviewComposition;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Adapts HTTP to the split of the whole net worth between accounts. */
final readonly class AccountOverviewCompositionController
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    // The priority keeps this static segment ahead of the `/accounts/{id}` detail route.
    #[Route('/api/v1/accounts/overview/composition', name: 'api_v1_accounts_overview_composition', methods: ['GET'], priority: 10)]
    public function composition(Request $request, ReadAccountOverviewComposition $readComposition): Response
    {
        $asOf = $request->query->getString('asOf');

        try {
            $composition = $readComposition('' === $asOf ? null : $asOf);
        } catch (InvalidAccountOverviewQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_account_overview_query');
        } catch (AccountOverviewCompositionTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.overview_composition_too_large', AccountGroupCompositionController::TYPE_TOO_LARGE);
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.net_worth_scope_too_large', AccountOverviewController::TYPE_SCOPE_TOO_LARGE);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.account_forbidden');
        }

        return new JsonResponse(
            AccountOverviewCompositionRepresentation::composition($composition),
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
