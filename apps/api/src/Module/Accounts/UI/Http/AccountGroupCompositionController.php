<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ReadAccountGroupComposition;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Adapts HTTP to the composition of one group between its sub-groups and accounts. */
final readonly class AccountGroupCompositionController
{
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/account-groups/{id}/composition', name: 'api_v1_account_groups_composition', methods: ['GET'])]
    public function composition(string $id, Request $request, ReadAccountGroupComposition $readComposition): Response
    {
        if (1 !== preg_match(self::UUID, $id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.account_group_not_found');
        }

        $asOf = $request->query->getString('asOf');

        try {
            $composition = $readComposition($id, '' === $asOf ? null : $asOf);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::groupComposition());
        }

        return $this->envelope->json(AccountGroupCompositionRepresentation::composition($composition));
    }
}
