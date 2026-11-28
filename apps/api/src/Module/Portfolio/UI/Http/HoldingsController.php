<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Portfolio\Application\InvalidHoldingsQuery;
use App\Module\Portfolio\Application\ReadHoldings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HoldingsController
{
    public const string TYPE_TOO_LARGE = '/problems/holdings-too-large';

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/holdings', name: 'api_v1_holdings', methods: ['GET'])]
    public function __invoke(Request $request, ReadHoldings $read): Response
    {
        try {
            $sort = $request->query->all()['sort'] ?? null;
            if (null !== $sort && !is_string($sort)) {
                throw new InvalidHoldingsQuery('The holdings order must be a string.');
            }

            return $this->envelope->json($read($sort));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PortfolioProblems::holdings());
        }
    }
}
