<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads the body of an account write, refusing the fields the API no longer accepts.
 */
final readonly class AccountHttp
{
    private const string REMOVED_TAGS_FIELD = 'tagGroupIds';

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
    public function readBody(Request $request): array|Response
    {
        $body = $this->envelope->body($request, BodyLimit::Standard, 'api.problem.account_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        // Tags were removed: a stale client must learn its write was not saved
        // rather than see the field silently dropped.
        if (array_key_exists(self::REMOVED_TAGS_FIELD, $body)) {
            return $this->envelope->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.invalid_account',
                extensions: ['errors' => [['field' => self::REMOVED_TAGS_FIELD, 'code' => 'unsupported_field']]],
            );
        }

        return $body;
    }
}
