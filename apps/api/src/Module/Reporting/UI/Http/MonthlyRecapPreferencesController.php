<?php

declare(strict_types=1);

namespace App\Module\Reporting\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads and stores which optional breakdowns the compact monthly recap shows.
 *
 * The selection picks rows and nothing else, so it has no bearing on any
 * published total; the recap answers with the same figures whatever it holds.
 */
final readonly class MonthlyRecapPreferencesController
{
    private const int MAX_BODY_BYTES = 16_384;
    private const array FIELDS = ['visibleCategoryIds', 'visibleAxes', 'version'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/reports/monthly/recap-preferences', name: 'api_v1_reports_monthly_recap_preferences_read', methods: ['GET'])]
    public function read(ReadRecapPreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->json($preferences);
    }

    #[Route('/api/v1/reports/monthly/recap-preferences', name: 'api_v1_reports_monthly_recap_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveRecapPreferences $save): Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.invalid_recap_preferences');
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidRecapPreferencesInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_recap_preferences');
        } catch (StaleRecapPreferencesVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.recap_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.monthly_projection_forbidden');
        }

        return $this->json($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveRecapPreferencesInput
    {
        $submitted = array_keys($body);
        sort($submitted);
        $expected = self::FIELDS;
        sort($expected);
        if ($submitted !== $expected) {
            throw new \UnexpectedValueException('A recap preference body carries exactly its declared fields.');
        }
        if (!is_int($body['version'])) {
            throw new \UnexpectedValueException('A recap preference version is an integer.');
        }

        return new SaveRecapPreferencesInput(
            self::strings($body['visibleCategoryIds']),
            self::strings($body['visibleAxes']),
            $body['version'],
        );
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('A recap preference selection is a list.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('A recap preference selection holds strings.');
            }
            $items[] = $item;
        }

        return $items;
    }

    private function json(RecapPreferencesView $view): JsonResponse
    {
        return new JsonResponse([
            'visibleCategoryIds' => $view->visibleCategoryIds,
            'visibleAxes' => $view->visibleAxes,
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
