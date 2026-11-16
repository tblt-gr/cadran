<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Problem responses shared by the goal controllers.
 */
final readonly class GoalProblems
{
    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    /** @param array<string, string> $details */
    public function problem(int $status, string $code, array $details = []): Response
    {
        return $this->envelope->problem($status, 'api.problem.goal_error', '/problems/'.$code, ['code' => $code, ...$details]);
    }

    public function notFound(): Response
    {
        return $this->problem(Response::HTTP_NOT_FOUND, 'goal_not_found');
    }

    public function forbidden(): Response
    {
        return $this->problem(Response::HTTP_FORBIDDEN, 'goal_forbidden');
    }
}
