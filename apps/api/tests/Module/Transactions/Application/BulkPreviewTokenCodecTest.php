<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Application\BulkEditChanges;
use App\Module\Transactions\Application\BulkPreviewTokenCodec;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class BulkPreviewTokenCodecTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string OTHER_WORKSPACE = '00000000-0000-7000-8000-0000000000a2';
    private const string A = '00000000-0000-7000-8000-000000000001';
    private const string B = '00000000-0000-7000-8000-000000000002';

    public function testATokenIsAcceptedForTheSameScopeChangesAndVersionsOnly(): void
    {
        $clock = new MockClock('2026-10-01 10:00:00');
        $codec = new BulkPreviewTokenCodec('secret', $clock);
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $changes = new BulkEditChanges(accountId: self::B);
        $token = $codec->issue($workspace, [self::A], $changes, [self::A => 3]);

        self::assertTrue($codec->accepts($token, $workspace, [self::A], $changes, [self::A => 3]));
        self::assertFalse($codec->accepts($token, $workspace, [self::A], $changes, [self::A => 4]));
        self::assertFalse($codec->accepts($token, $workspace, [self::A, self::B], $changes, [self::A => 3]));
        self::assertFalse($codec->accepts($token, $workspace, [self::A], new BulkEditChanges(accountId: self::A), [self::A => 3]));
        self::assertFalse($codec->accepts($token, WorkspaceScope::fromString(self::OTHER_WORKSPACE), [self::A], $changes, [self::A => 3]));
        self::assertFalse((new BulkPreviewTokenCodec('another secret', $clock))->accepts($token, $workspace, [self::A], $changes, [self::A => 3]));
    }

    public function testATokenExpiresAfterTenMinutes(): void
    {
        $clock = new MockClock('2026-10-01 10:00:00');
        $codec = new BulkPreviewTokenCodec('secret', $clock);
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $changes = new BulkEditChanges(bookedOn: new \DateTimeImmutable('2026-10-05'));
        $token = $codec->issue($workspace, [self::A], $changes, [self::A => 1]);

        $clock->sleep(BulkPreviewTokenCodec::TTL_SECONDS);
        self::assertTrue($codec->accepts($token, $workspace, [self::A], $changes, [self::A => 1]));
        $clock->sleep(1);
        self::assertFalse($codec->accepts($token, $workspace, [self::A], $changes, [self::A => 1]));
    }

    public function testMalformedOrAbsentTokensAreRefused(): void
    {
        $codec = new BulkPreviewTokenCodec('secret', new MockClock('2026-10-01 10:00:00'));
        $workspace = WorkspaceScope::fromString(self::WORKSPACE);
        $changes = new BulkEditChanges(accountId: self::B);

        foreach ([null, '', 'x', 'a.b.c', '!!!.???', rtrim(strtr(base64_encode('9999999999'), '+/', '-_'), '=').'.bad'] as $token) {
            self::assertFalse($codec->accepts($token, $workspace, [self::A], $changes, [self::A => 1]));
        }
    }
}
