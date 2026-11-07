<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\HoldingsTooLarge;
use App\Module\Portfolio\Application\InvalidHoldingsQuery;
use App\Module\Portfolio\Application\ReadHoldings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class HoldingsController
{
    public const string TYPE_TOO_LARGE = '/problems/holdings-too-large';

    public function __construct(private TranslatorInterface $translator)
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
            $response = new JsonResponse($read($sort));
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } catch (\Throwable $exception) {
            return match (true) {
                $exception instanceof InvalidHoldingsQuery => $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_holdings_query'),
                $exception instanceof HoldingsTooLarge => $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'holdings_too_large', self::TYPE_TOO_LARGE),
                default => throw $exception,
            };
        }
    }

    private function problem(int $status, string $code, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        $key = 'api.problem.'.$code;

        return ApiProblem::response($status, $this->translator->trans($key.'.title'), $this->translator->trans($key.'.detail'), $type, extensions: ['code' => $code]);
    }
}
