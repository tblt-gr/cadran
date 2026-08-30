<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Domain;

use App\Module\Identity\Domain\Membership;
use PHPUnit\Framework\TestCase;

final class MembershipTest extends TestCase
{
    public function testItAcceptsAnOwnerMembership(): void
    {
        $membership = new Membership(
            id: '00000000-0000-7000-8000-0000000000b1',
            workspaceId: '00000000-0000-7000-8000-0000000000a1',
            userId: '00000000-0000-7000-8000-000000000001',
            role: Membership::OWNER,
            createdAt: new \DateTimeImmutable(),
        );

        self::assertSame('OWNER', $membership->role);
    }

    public function testItRejectsAnUnsupportedRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Membership(
            id: '00000000-0000-7000-8000-0000000000b1',
            workspaceId: '00000000-0000-7000-8000-0000000000a1',
            userId: '00000000-0000-7000-8000-000000000001',
            role: 'MEMBER',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testItRejectsAMissingWorkspaceOrUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Membership(
            id: '00000000-0000-7000-8000-0000000000b1',
            workspaceId: '',
            userId: '00000000-0000-7000-8000-000000000001',
            role: Membership::OWNER,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
