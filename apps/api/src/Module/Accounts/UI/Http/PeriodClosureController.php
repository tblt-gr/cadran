<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ClosePeriod;
use App\Module\Accounts\Application\ClosePeriodInput;
use App\Module\Accounts\Application\ListPeriodClosures;
use App\Module\Accounts\Application\ReadPeriodStatus;
use App\Module\Accounts\Application\ReopenPeriod;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Adapts HTTP to the monthly closing of a workspace. Reads are never blocked by a closure. */
final readonly class PeriodClosureController
{
    private const string PERIOD = '\d{4}-\d{2}';

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/period-closures', name: 'api_v1_period_closures_list', methods: ['GET'])]
    public function list(Request $request, ListPeriodClosures $listClosures): Response
    {
        $year = $request->query->getString('year');
        if (1 !== preg_match('/^\d{4}$/D', $year)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_period_closure_query');
        }

        try {
            $closures = $listClosures((int) $year);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PeriodClosureProblems::list());
        }

        return $this->envelope->json(['items' => array_map(PeriodClosureRepresentation::one(...), $closures)]);
    }

    #[Route('/api/v1/periods/{period}', name: 'api_v1_periods_read', requirements: ['period' => self::PERIOD], methods: ['GET'])]
    public function read(string $period, ReadPeriodStatus $readStatus): Response
    {
        try {
            $status = $readStatus($period);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PeriodClosureProblems::read());
        }

        return $this->envelope->json(PeriodClosureRepresentation::status($status));
    }

    #[Route('/api/v1/periods/{period}/closure', name: 'api_v1_periods_close', requirements: ['period' => self::PERIOD], methods: ['PUT'])]
    public function close(string $period, Request $request, ClosePeriod $closePeriod): Response
    {
        // Closing without any waived condition needs no body at all.
        $body = '' === $request->getContent()
            ? []
            : $this->envelope->body($request, BodyLimit::Medium, 'api.problem.period_closure_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        $overrides = self::overrides($body);
        if (null === $overrides) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        }

        try {
            $closure = $closePeriod(new ClosePeriodInput($period, $overrides));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PeriodClosureProblems::close());
        }

        return $this->envelope->json(PeriodClosureRepresentation::one($closure), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/periods/{period}/reopening', name: 'api_v1_periods_reopen', requirements: ['period' => self::PERIOD], methods: ['POST'])]
    public function reopen(string $period, Request $request, ReopenPeriod $reopenPeriod): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Medium, 'api.problem.period_closure_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }
        $reason = $body['reason'] ?? null;
        $version = $body['version'] ?? null;
        if ([] !== array_diff(array_keys($body), ['reason', 'version']) || !is_string($reason) || !is_int($version)) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        }

        try {
            $closure = $reopenPeriod($period, $version, $reason);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, PeriodClosureProblems::reopen());
        }

        return $this->envelope->json(PeriodClosureRepresentation::one($closure));
    }

    /**
     * `overrides` is an object naming each waived condition with its reason.
     * There is deliberately no flag that waives everything.
     *
     * @param array<mixed> $body
     *
     * @return array<string, string>|null null when the shape is wrong
     */
    private static function overrides(array $body): ?array
    {
        if ([] !== array_diff(array_keys($body), ['overrides'])) {
            return null;
        }
        $submitted = $body['overrides'] ?? [];
        if (!is_array($submitted) || ([] !== $submitted && array_is_list($submitted))) {
            return null;
        }
        $overrides = [];
        foreach ($submitted as $condition => $reason) {
            if (!is_string($reason)) {
                return null;
            }
            $overrides[(string) $condition] = $reason;
        }

        return $overrides;
    }
}
