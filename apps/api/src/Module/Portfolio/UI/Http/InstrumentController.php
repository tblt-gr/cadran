<?php

declare(strict_types=1);

namespace App\Module\Portfolio\UI\Http;

use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Portfolio\Application\CreateInstrument;
use App\Module\Portfolio\Application\CreateInstrumentInput;
use App\Module\Portfolio\Application\DeactivateInstrument;
use App\Module\Portfolio\Application\InstrumentDuplicateCandidates;
use App\Module\Portfolio\Application\InstrumentIsinExists;
use App\Module\Portfolio\Application\InstrumentLimitReached;
use App\Module\Portfolio\Application\InstrumentNotFound;
use App\Module\Portfolio\Application\InvalidInstrumentInput;
use App\Module\Portfolio\Application\ListInstruments;
use App\Module\Portfolio\Application\ReactivateInstrument;
use App\Module\Portfolio\Application\ReadInstrument;
use App\Module\Portfolio\Application\StaleInstrumentVersion;
use App\Module\Portfolio\Application\UpdateInstrument;
use App\Module\Portfolio\Application\UpdateInstrumentInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class InstrumentController
{
    private const int MAX_BODY_BYTES = 16_384;
    private const array REQUIRED_WRITE_FIELDS = ['name', 'instrumentType', 'currencyCode'];
    private const array OPTIONAL_WRITE_FIELDS = [
        'isin', 'ticker', 'venueMic', 'assetClass', 'region', 'quantityPrecision', 'pricePrecision', 'confirmDuplicate',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/instruments', name: 'api_v1_instruments_list', methods: ['GET'])]
    public function list(Request $request, ListInstruments $list): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage)) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_instrument_query');
        }
        try {
            $result = $list(
                $request->query->has('q') ? $request->query->getString('q') : null,
                $request->query->getString('status'),
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
                $request->query->has('currencyCode') ? $request->query->getString('currencyCode') : null,
                $request->query->has('instrumentType') ? $request->query->getString('instrumentType') : null,
            );
        } catch (InvalidInstrumentInput $exception) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'instrument_forbidden');
        }

        return self::json(InstrumentRepresentation::page($result));
    }

    #[Route('/api/v1/instruments', name: 'api_v1_instruments_create', methods: ['POST'])]
    public function create(Request $request, CreateInstrument $create): Response
    {
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = InstrumentPayload::of($body, self::REQUIRED_WRITE_FIELDS, self::OPTIONAL_WRITE_FIELDS);
            $instrument = $create(new CreateInstrumentInput(
                $payload->string('name'), $payload->nullableString('isin'), $payload->nullableString('ticker'),
                $payload->nullableString('venueMic'), $payload->string('instrumentType'), $payload->nullableString('assetClass'),
                $payload->nullableString('region'), $payload->string('currencyCode'), $payload->nullableInteger('quantityPrecision'),
                $payload->nullableInteger('pricePrecision'), $payload->boolean('confirmDuplicate'),
            ));
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_instrument_payload');
        } catch (InvalidInstrumentInput $exception) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode);
        } catch (InstrumentIsinExists $exception) {
            return $this->problem(Response::HTTP_CONFLICT, 'instrument_isin_exists', ['existingId' => $exception->existingId]);
        } catch (InstrumentDuplicateCandidates $exception) {
            return $this->problem(Response::HTTP_CONFLICT, 'instrument_duplicate_candidates', [
                'candidates' => array_map(InstrumentRepresentation::candidate(...), $exception->candidates),
            ]);
        } catch (InstrumentLimitReached) {
            return $this->problem(Response::HTTP_CONFLICT, 'instrument_limit_reached');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'instrument_forbidden');
        }

        return self::json(InstrumentRepresentation::one($instrument), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/instruments/{id}', name: 'api_v1_instruments_show', methods: ['GET'])]
    public function show(string $id, ReadInstrument $read): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        }
        try {
            $instrument = $read($id);
        } catch (InstrumentNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'instrument_forbidden');
        }

        return self::json(InstrumentRepresentation::one($instrument));
    }

    #[Route('/api/v1/instruments/{id}', name: 'api_v1_instruments_update', methods: ['PUT'])]
    public function update(string $id, Request $request, UpdateInstrument $update): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $payload = InstrumentPayload::of($body, [...self::REQUIRED_WRITE_FIELDS, 'version'], self::OPTIONAL_WRITE_FIELDS);
            $instrument = $update($id, new UpdateInstrumentInput(
                $payload->string('name'), $payload->nullableString('isin'), $payload->nullableString('ticker'),
                $payload->nullableString('venueMic'), $payload->string('instrumentType'), $payload->nullableString('assetClass'),
                $payload->nullableString('region'), $payload->string('currencyCode'), $payload->nullableInteger('quantityPrecision'),
                $payload->nullableInteger('pricePrecision'), $payload->boolean('confirmDuplicate'), $payload->integer('version'),
            ));
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_instrument_payload');
        } catch (InvalidInstrumentInput $exception) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, $exception->problemCode);
        } catch (InstrumentNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        } catch (StaleInstrumentVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'stale_instrument_version');
        } catch (InstrumentIsinExists $exception) {
            return $this->problem(Response::HTTP_CONFLICT, 'instrument_isin_exists', ['existingId' => $exception->existingId]);
        } catch (InstrumentDuplicateCandidates $exception) {
            return $this->problem(Response::HTTP_CONFLICT, 'instrument_duplicate_candidates', [
                'candidates' => array_map(InstrumentRepresentation::candidate(...), $exception->candidates),
            ]);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'instrument_forbidden');
        }

        return self::json(InstrumentRepresentation::one($instrument));
    }

    #[Route('/api/v1/instruments/{id}/deactivate', name: 'api_v1_instruments_deactivate', methods: ['POST'])]
    public function deactivate(string $id, Request $request, DeactivateInstrument $deactivate): Response
    {
        return $this->lifecycle($id, $request, $deactivate);
    }

    #[Route('/api/v1/instruments/{id}/reactivate', name: 'api_v1_instruments_reactivate', methods: ['POST'])]
    public function reactivate(string $id, Request $request, ReactivateInstrument $reactivate): Response
    {
        return $this->lifecycle($id, $request, $reactivate);
    }

    /** @param callable(string, int): \App\Module\Portfolio\Application\InstrumentView $change */
    private function lifecycle(string $id, Request $request, callable $change): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        }
        $body = $this->body($request);
        if ($body instanceof Response) {
            return $body;
        }
        try {
            $version = InstrumentPayload::of($body, ['version'])->integer('version');
            $instrument = $change($id, $version);
        } catch (\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'invalid_instrument_payload');
        } catch (InvalidInstrumentInput $exception) {
            $status = in_array($exception->problemCode, ['instrument_already_active', 'instrument_already_inactive'], true)
                ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY;

            return $this->problem($status, $exception->problemCode);
        } catch (InstrumentNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'instrument_not_found');
        } catch (StaleInstrumentVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'stale_instrument_version');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'instrument_forbidden');
        }

        return self::json(InstrumentRepresentation::one($instrument));
    }

    /** @return array<mixed>|Response */
    private function body(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'instrument_payload_too_large');
        }
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'invalid_request');
        }
    }

    /** @param array<string, mixed> $extensions */
    private function problem(int $status, string $code, array $extensions = []): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans('api.problem.'.$code.'.title'),
            $this->translator->trans('api.problem.'.$code.'.detail'),
            '/problems/'.str_replace('_', '-', $code),
            extensions: ['code' => $code, ...$extensions],
        );
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }
}
