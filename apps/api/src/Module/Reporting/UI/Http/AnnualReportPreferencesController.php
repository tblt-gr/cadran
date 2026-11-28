<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
use App\Module\Reporting\Application\AnnualPreferencesView;
use App\Module\Reporting\Application\InvalidAnnualPreferencesInput;
use App\Module\Reporting\Application\ReadAnnualPreferences;
use App\Module\Reporting\Application\SaveAnnualPreferences;
use App\Module\Reporting\Application\SaveAnnualPreferencesInput;
use App\Module\Reporting\Application\StaleAnnualPreferencesVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reads and stores which columns the annual report shows and whether its aggregates count the running month and whether the goals colour its figures.
 *
 * The selection picks columns and nothing else, so it has no bearing on any
 * published figure.
 */
final readonly class AnnualReportPreferencesController
{
    private const array FIELDS = ['columns', 'incompleteMonths', 'version'];
    private const string OPTIONAL_FIELD = 'goalMarks';

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/reports/annual/preferences', name: 'api_v1_reports_annual_preferences_read', methods: ['GET'])]
    public function read(ReadAnnualPreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->answer($preferences);
    }

    #[Route('/api/v1/reports/annual/preferences', name: 'api_v1_reports_annual_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveAnnualPreferences $save): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.invalid_annual_preferences');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidAnnualPreferencesInput|MalformedPayload) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_annual_preferences');
        } catch (StaleAnnualPreferencesVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.annual_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->answer($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveAnnualPreferencesInput
    {
        $payload = JsonPayload::of($body, self::FIELDS, [self::OPTIONAL_FIELD]);

        return new SaveAnnualPreferencesInput(
            // The use case bounds the selection against the column catalogue.
            $payload->stringList('columns', \PHP_INT_MAX),
            $payload->string('incompleteMonths'),
            $payload->integer('version'),
            array_key_exists(self::OPTIONAL_FIELD, $body) ? $payload->boolean(self::OPTIONAL_FIELD) : null,
        );
    }

    private function answer(AnnualPreferencesView $view): JsonResponse
    {
        return $this->envelope->json([
            'columns' => $view->columns,
            'incompleteMonths' => $view->incompleteMonths,
            'goalMarks' => $view->goalMarks,
            'version' => $view->version,
        ]);
    }
}
