<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ClosePeriod;
use App\Module\Accounts\Application\ClosePeriodInput;
use App\Module\Accounts\Application\InvalidPeriodClosureInput;
use App\Module\Accounts\Application\ListPeriodClosures;
use App\Module\Accounts\Application\PeriodClosingBlocked;
use App\Module\Accounts\Application\PeriodClosureConflict;
use App\Module\Accounts\Application\PeriodClosureForbidden;
use App\Module\Accounts\Application\PeriodClosureNotFound;
use App\Module\Accounts\Application\ReadPeriodStatus;
use App\Module\Accounts\Application\ReopenPeriod;
use App\Module\Accounts\Application\StalePeriodClosureVersion;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Adapts HTTP to the monthly closing of a workspace. Reads are never blocked by a closure. */
final readonly class PeriodClosureController
{
    public const string TYPE_BLOCKED = '/problems/period-closing-blocked';
    public const string TYPE_CONFLICT = '/problems/period-closure-conflict';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';

    private const int MAX_BODY_BYTES = 8_192;
    private const string PERIOD = '\d{4}-\d{2}';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/period-closures', name: 'api_v1_period_closures_list', methods: ['GET'])]
    public function list(Request $request, ListPeriodClosures $listClosures): Response
    {
        $year = $request->query->getString('year');
        if (1 !== preg_match('/^\d{4}$/D', $year)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_period_closure_query');
        }

        try {
            $closures = $listClosures((int) $year);
        } catch (InvalidPeriodClosureInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_period_closure_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_no_workspace');
        }

        return self::json(['items' => array_map(PeriodClosureRepresentation::one(...), $closures)]);
    }

    #[Route('/api/v1/periods/{period}', name: 'api_v1_periods_read', requirements: ['period' => self::PERIOD], methods: ['GET'])]
    public function read(string $period, ReadPeriodStatus $readStatus): Response
    {
        try {
            $status = $readStatus($period);
        } catch (InvalidPeriodClosureInput) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_no_workspace');
        }

        return self::json(PeriodClosureRepresentation::status($status));
    }

    #[Route('/api/v1/periods/{period}/closure', name: 'api_v1_periods_close', requirements: ['period' => self::PERIOD], methods: ['PUT'])]
    public function close(string $period, Request $request, ClosePeriod $closePeriod): Response
    {
        $body = $this->readBody($request, true);
        if ($body instanceof Response) {
            return $body;
        }
        $overrides = self::overrides($body);
        if (null === $overrides) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        }

        try {
            $closure = $closePeriod(new ClosePeriodInput($period, $overrides));
        } catch (PeriodClosingBlocked $exception) {
            return ApiProblem::response(
                Response::HTTP_CONFLICT,
                $this->translator->trans('api.problem.period_closing_blocked.title'),
                $this->translator->trans('api.problem.period_closing_blocked.detail'),
                self::TYPE_BLOCKED,
                extensions: ['blockers' => PeriodClosureRepresentation::blockers($exception->blockers)],
            );
        } catch (InvalidPeriodClosureInput) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        } catch (PeriodClosureConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.period_closure_conflict', self::TYPE_CONFLICT);
        } catch (PeriodClosureForbidden) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_forbidden');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_no_workspace');
        }

        return self::json(PeriodClosureRepresentation::one($closure), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/periods/{period}/reopening', name: 'api_v1_periods_reopen', requirements: ['period' => self::PERIOD], methods: ['POST'])]
    public function reopen(string $period, Request $request, ReopenPeriod $reopenPeriod): Response
    {
        $body = $this->readBody($request, false);
        if ($body instanceof Response) {
            return $body;
        }
        $reason = $body['reason'] ?? null;
        $version = $body['version'] ?? null;
        if ([] !== array_diff(array_keys($body), ['reason', 'version']) || !is_string($reason) || !is_int($version)) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        }

        try {
            $closure = $reopenPeriod($period, $version, $reason);
        } catch (InvalidPeriodClosureInput) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_period_closure');
        } catch (PeriodClosureNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.period_closure_not_found');
        } catch (StalePeriodClosureVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.period_closure_stale_version', self::TYPE_STALE_VERSION);
        } catch (PeriodClosureConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.period_closure_conflict', self::TYPE_CONFLICT);
        } catch (PeriodClosureForbidden) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_forbidden');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.period_closure_no_workspace');
        }

        return self::json(PeriodClosureRepresentation::one($closure));
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

    /** @return array<mixed>|Response */
    private function readBody(Request $request, bool $optional): array|Response
    {
        if ($optional && '' === $request->getContent()) {
            return [];
        }
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.period_closure_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
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
