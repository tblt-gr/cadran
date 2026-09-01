<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

/**
 * Keyset position in the trail, ordered newest first on (occurred_at, id).
 * Both parts are needed: events written inside one transaction share a
 * timestamp, and only the identifier then separates them.
 *
 * The encoded form is opaque to clients but not a secret: it names a row the
 * caller has just been shown, and the workspace filter is applied server-side
 * regardless of what the cursor claims.
 */
final readonly class AuditTrailCursor
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        public \DateTimeImmutable $occurredAt,
        public string $eventId,
    ) {
    }

    public function encode(): string
    {
        return rtrim(strtr(base64_encode(
            $this->occurredAt->format(self::TIMESTAMP_FORMAT).' '.$this->eventId,
        ), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if (false === $decoded) {
            throw new InvalidAuditTrailQuery('The audit trail cursor is not decodable.');
        }

        $parts = explode(' ', $decoded);
        if (2 !== count($parts)) {
            throw new InvalidAuditTrailQuery('The audit trail cursor is malformed.');
        }

        [$timestamp, $eventId] = $parts;
        $occurredAt = \DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $timestamp);
        if (false === $occurredAt || 1 !== preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $eventId)) {
            throw new InvalidAuditTrailQuery('The audit trail cursor does not name a known position.');
        }

        return new self($occurredAt, $eventId);
    }
}
