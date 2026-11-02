<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Identity\Application\InterfacePreferencesView;
use App\Module\Identity\Application\InvalidInterfacePreferencesInput;
use App\Module\Identity\Application\ReadInterfacePreferences;
use App\Module\Identity\Application\SaveInterfacePreferences;
use App\Module\Identity\Application\SaveInterfacePreferencesInput;
use App\Module\Identity\Application\StaleInterfacePreferencesVersion;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reads and stores the display preferences of the calling workspace.
 *
 * The workspace is the caller's own, never taken from the request. The reads that
 * honour a preference receive it as a query parameter from the web client, so
 * they stay deterministic and never consult this resource themselves.
 */
final readonly class InterfacePreferencesController
{
    private const int MAX_BODY_BYTES = 1_024;
    private const array FIELDS = ['includeUpcoming', 'version'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/interface-preferences', name: 'api_v1_interface_preferences_read', methods: ['GET'])]
    public function read(ReadInterfacePreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.interface_preferences_forbidden');
        }

        return $this->json($preferences);
    }

    #[Route('/api/v1/interface-preferences', name: 'api_v1_interface_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveInterfacePreferences $save): Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.invalid_interface_preferences');
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidInterfacePreferencesInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_interface_preferences');
        } catch (StaleInterfacePreferencesVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.interface_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.interface_preferences_forbidden');
        }

        return $this->json($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveInterfacePreferencesInput
    {
        $submitted = array_keys($body);
        sort($submitted);
        $expected = self::FIELDS;
        sort($expected);
        if ($submitted !== $expected) {
            throw new \UnexpectedValueException('An interface preference body carries exactly its declared fields.');
        }
        if (!is_bool($body['includeUpcoming']) || !is_int($body['version'])) {
            throw new \UnexpectedValueException('An interface preference is a boolean and its version an integer.');
        }

        return new SaveInterfacePreferencesInput($body['includeUpcoming'], $body['version']);
    }

    private function json(InterfacePreferencesView $view): JsonResponse
    {
        return new JsonResponse(
            ['includeUpcoming' => $view->includeUpcoming, 'version' => $view->version],
            Response::HTTP_OK,
            ['Cache-Control' => 'no-store'],
        );
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
