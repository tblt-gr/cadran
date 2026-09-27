<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads and stores which columns the annual report shows and whether its aggregates count the running month.
 *
 * The selection picks columns and nothing else, so it has no bearing on any
 * published figure.
 */
final readonly class AnnualReportPreferencesController
{
    private const int MAX_BODY_BYTES = 16_384;
    private const array FIELDS = ['columns', 'incompleteMonths', 'version'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/annual/preferences', name: 'api_v1_reports_annual_preferences_read', methods: ['GET'])]
    public function read(ReadAnnualPreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->json($preferences);
    }

    #[Route('/api/v1/reports/annual/preferences', name: 'api_v1_reports_annual_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveAnnualPreferences $save): Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.invalid_annual_preferences');
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidAnnualPreferencesInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_annual_preferences');
        } catch (StaleAnnualPreferencesVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.annual_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->json($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveAnnualPreferencesInput
    {
        $submitted = array_keys($body);
        sort($submitted);
        $expected = self::FIELDS;
        sort($expected);
        if ($submitted !== $expected) {
            throw new \UnexpectedValueException('An annual report preference body carries exactly its declared fields.');
        }
        if (!is_int($body['version'])) {
            throw new \UnexpectedValueException('An annual report preference version is an integer.');
        }

        if (!is_string($body['incompleteMonths'])) {
            throw new \UnexpectedValueException('An annual preference incomplete-month setting is text.');
        }

        return new SaveAnnualPreferencesInput(
            self::strings($body['columns']),
            $body['incompleteMonths'],
            $body['version'],
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('An annual report preference selection is a list.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('An annual report preference selection holds strings.');
            }
            $items[] = $item;
        }

        return $items;
    }

    private function json(AnnualPreferencesView $view): JsonResponse
    {
        return new JsonResponse([
            'columns' => $view->columns,
            'incompleteMonths' => $view->incompleteMonths,
            'version' => $view->version,
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $key): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
        );
    }
}
