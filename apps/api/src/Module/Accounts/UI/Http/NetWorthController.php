<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Application\ReadNetWorthHistory;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adapts HTTP to the net-worth read model. The aggregation itself belongs to
 * the domain: nothing here adds, divides or rounds a figure.
 */
final readonly class NetWorthController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/net-worth', name: 'api_v1_net_worth_read', methods: ['GET'])]
    public function read(Request $request, ReadNetWorth $readNetWorth): Response
    {
        $asOf = $request->query->getString('asOf');
        $comparedTo = $request->query->getString('comparedTo');
        if (!self::isoDayOrEmpty($asOf) || !self::isoDayOrEmpty($comparedTo)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        }

        try {
            $netWorth = $readNetWorth($asOf ?: null, $comparedTo ?: null);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::netWorthRead());
        }

        return $this->envelope->json(NetWorthRepresentation::of($netWorth));
    }

    #[Route('/api/v1/net-worth/history', name: 'api_v1_net_worth_history', methods: ['GET'])]
    public function history(Request $request, ReadNetWorthHistory $readHistory): Response
    {
        $asOf = $request->query->getString('asOf');
        $period = $request->query->all()['period'] ?? null;
        if ($request->query->has('period') && (!is_string($period)
            || !in_array($period, ['ALL', 'LAST_12_MONTHS', 'YTD'], true)
            || $request->query->has('months'))) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_net_worth_query');
        }
        $months = $request->query->getString('months');
        if (!self::isoDayOrEmpty($asOf) || !self::unsignedIntegerOrEmpty($months)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        }

        try {
            $history = $readHistory($asOf ?: null, '' === $months ? null : (int) $months, is_string($period) ? $period : null);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, AccountOverviewProblems::netWorthHistory());
        }

        return $this->envelope->json(NetWorthRepresentation::history($history));
    }

    private static function isoDayOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value);
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,3}$/D', $value);
    }
}
