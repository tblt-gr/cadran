<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\MalformedPayload;
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

/**
 * Reads and stores the display preferences of the calling workspace.
 *
 * The workspace is the caller's own, never taken from the request. The reads that
 * honour a preference receive it as a query parameter from the web client, so
 * they stay deterministic and never consult this resource themselves.
 */
final readonly class InterfacePreferencesController
{
    private const array FIELDS = ['includeUpcoming', 'version'];

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/interface-preferences', name: 'api_v1_interface_preferences_read', methods: ['GET'])]
    public function read(ReadInterfacePreferences $read): Response
    {
        try {
            $preferences = $read();
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.interface_preferences_forbidden');
        }

        return $this->answer($preferences);
    }

    #[Route('/api/v1/interface-preferences', name: 'api_v1_interface_preferences_save', methods: ['PUT'])]
    public function save(Request $request, SaveInterfacePreferences $save): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Tiny, 'api.problem.invalid_interface_preferences');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $preferences = $save(self::input($body));
        } catch (InvalidInterfacePreferencesInput|MalformedPayload) {
            return $this->envelope->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_interface_preferences');
        } catch (StaleInterfacePreferencesVersion) {
            return $this->envelope->problem(Response::HTTP_CONFLICT, 'api.problem.interface_preferences_conflict');
        } catch (WorkspaceAccessDenied) {
            return $this->envelope->problem(Response::HTTP_FORBIDDEN, 'api.problem.interface_preferences_forbidden');
        }

        return $this->answer($preferences);
    }

    /** @param array<mixed> $body */
    private static function input(array $body): SaveInterfacePreferencesInput
    {
        $payload = JsonPayload::of($body, self::FIELDS);

        return new SaveInterfacePreferencesInput($payload->boolean('includeUpcoming'), $payload->integer('version'));
    }

    private function answer(InterfacePreferencesView $view): JsonResponse
    {
        return $this->envelope->json(['includeUpcoming' => $view->includeUpcoming, 'version' => $view->version]);
    }
}
