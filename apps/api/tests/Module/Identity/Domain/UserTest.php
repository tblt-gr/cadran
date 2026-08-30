<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Domain;

use App\Module\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testItAcceptsAValidUser(): void
    {
        $user = new User(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'owner@example.test',
            displayName: 'Owner',
            createdAt: new \DateTimeImmutable(),
        );

        self::assertSame('owner@example.test', $user->email);
    }

    public function testItRejectsAMalformedEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'not-an-email',
            displayName: 'Owner',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testItRejectsABlankDisplayName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'owner@example.test',
            displayName: '  ',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testItRejectsAnEmptyIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User(
            id: '',
            email: 'owner@example.test',
            displayName: 'Owner',
            createdAt: new \DateTimeImmutable(),
        );
    }
}
