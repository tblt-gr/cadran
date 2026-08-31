<?php

declare(strict_types=1);

namespace App\Tests\Module\Audit\Application;

use App\Module\Audit\Application\AuditTrailCursor;
use App\Module\Audit\Application\InvalidAuditTrailQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditTrailCursorTest extends TestCase
{
    private const string EVENT_ID = '0192f3c4-5678-7abc-8def-0123456789ab';

    public function testAnEncodedCursorRoundTripsWithMicrosecondPrecision(): void
    {
        $occurredAt = new \DateTimeImmutable('2026-08-31T10:11:12.123456+02:00');

        $decoded = AuditTrailCursor::decode((new AuditTrailCursor($occurredAt, self::EVENT_ID))->encode());

        self::assertSame($occurredAt->format('Y-m-d\TH:i:s.uP'), $decoded->occurredAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(self::EVENT_ID, $decoded->eventId);
    }

    public function testTheEncodedFormIsUrlSafe(): void
    {
        $encoded = (new AuditTrailCursor(new \DateTimeImmutable('2026-08-31T10:11:12.123456+00:00'), self::EVENT_ID))->encode();

        self::assertSame($encoded, rawurlencode($encoded));
    }

    #[DataProvider('rejectedCursors')]
    public function testItRefusesACursorItDidNotIssue(string $cursor): void
    {
        $this->expectException(InvalidAuditTrailQuery::class);

        AuditTrailCursor::decode($cursor);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedCursors(): iterable
    {
        yield 'not base64' => ['*** not base64 ***'];
        yield 'single part' => [self::encodeRaw('2026-08-31T10:11:12.123456+00:00')];
        yield 'unparsable timestamp' => [self::encodeRaw('yesterday '.self::EVENT_ID)];
        yield 'identifier is not a uuid' => [self::encodeRaw('2026-08-31T10:11:12.123456+00:00 1 OR 1=1')];
        yield 'empty' => [''];
    }

    private static function encodeRaw(string $payload): string
    {
        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }
}
