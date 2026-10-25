<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Domain;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Catalog\Domain\AccountKind;
use PHPUnit\Framework\TestCase;

final class PrimaryAccountTest extends TestCase
{
    public function testOnlyAnActiveCurrentAccountCanBecomePrimary(): void
    {
        $now = new \DateTimeImmutable('2026-09-04T10:00:00Z');
        $account = AccountFixture::account(kind: AccountKind::CURRENT)->withPrimary(true, $now);
        self::assertTrue($account->isPrimary);
        self::assertFalse($account->withPrimary(false, $now)->isPrimary);
        self::assertFalse($account->archive($now)->isPrimary);
        self::assertTrue($account->markUsed($now)->isPrimary);
    }

    public function testSavingsCannotBecomePrimary(): void
    {
        $this->expectException(InvalidAccount::class);
        AccountFixture::account()->withPrimary(true, new \DateTimeImmutable('2026-09-04T10:00:00Z'));
    }

    public function testSnapshotCurrentAccountCannotBecomePrimary(): void
    {
        $this->expectException(InvalidAccount::class);
        AccountFixture::account(kind: AccountKind::CURRENT, valuationMode: AccountValuationMode::SNAPSHOTS)
            ->withPrimary(true, new \DateTimeImmutable('2026-09-04T10:00:00Z'));
    }

    public function testClosedCurrentAccountCannotBecomePrimary(): void
    {
        $this->expectException(InvalidAccount::class);
        AccountFixture::account(kind: AccountKind::CURRENT, closedOn: '2026-02-01')
            ->withPrimary(true, new \DateTimeImmutable('2026-09-04T10:00:00Z'));
    }
}
