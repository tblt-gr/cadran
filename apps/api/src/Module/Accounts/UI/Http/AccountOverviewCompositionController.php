<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ReadAccountOverviewComposition;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Adapts HTTP to the split of the whole net worth between accounts. */
final readonly class AccountOverviewCompositionController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    // The priority keeps this static segment ahead of the `/accounts/{id}` detail route.
    #[Route('/api/v1/accounts/overview/composition', name: 'api_v1_accounts_overview_composition', methods: ['GET'], priority: 10)]
    public function composition(Request $request, ReadAccountOverviewComposition $readComposition): Response
    {
        $asOf = $request->query->getString('asOf');

        try {
            $composition = $readComposition('' === $asOf ? null : $asOf);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::composition());
        }

        return $this->envelope->json(AccountOverviewCompositionRepresentation::composition($composition));
    }
}
