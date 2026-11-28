<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Reporting\Application\InvalidRecapPreferencesInput;
use App\Module\Reporting\Application\ReadRecapPreferences;
use App\Module\Reporting\Application\RecapPreferencesView;
use App\Module\Reporting\Application\SaveRecapPreferences;
use App\Module\Reporting\Application\SaveRecapPreferencesInput;
use App\Module\Reporting\Application\StaleRecapPreferencesVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reads and stores which optional breakdowns the compact monthly recap shows.
 *
 * The selection picks rows and nothing else, so it has no bearing on any
 * published total; the recap answers with the same figures whatever it holds.
 */
final readonly class MonthlyRecapPreferencesController
{
    private const array FIELDS = ['visibleCategoryIds', 'visibleAxes', 'version'];

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/reports/monthly/recap-preferences', name: 'api_v1_reports_monthly_recap_preferences_read', methods: ['GET'])]
    public function read(ReadRecapPreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->answer($preferences);
    }

    #[Route('/api/v1/reports/monthly/recap-preferences', name: 'api_v1_reports_monthly_recap_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveRecapPreferences $save): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.invalid_recap_preferences');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidRecapPreferencesInput|MalformedPayload) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_recap_preferences');
        } catch (StaleRecapPreferencesVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.recap_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->answer($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveRecapPreferencesInput
    {
        $payload = JsonPayload::of($body, self::FIELDS);

        // The selection is bounded by the use case, and by the body ceiling before it.
        return new SaveRecapPreferencesInput(
            $payload->stringList('visibleCategoryIds', \PHP_INT_MAX),
            $payload->stringList('visibleAxes', \PHP_INT_MAX),
            $payload->integer('version'),
        );
    }

    private function answer(RecapPreferencesView $view): JsonResponse
    {
        return $this->envelope->json([
            'visibleCategoryIds' => $view->visibleCategoryIds,
            'visibleAxes' => $view->visibleAxes,
            'version' => $view->version,
        ]);
    }
}
