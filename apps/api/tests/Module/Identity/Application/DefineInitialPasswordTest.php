<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Application;

use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\AuthenticationUserRepository;
use App\Module\Identity\Application\DefineInitialPassword;
use App\Module\Identity\Application\DefineInitialPasswordInput;
use App\Module\Identity\Application\InitialPasswordAlreadyDefined;
use App\Module\Identity\Application\OwnerAccountNotProvisioned;
use App\Module\Identity\Application\OwnerCredentials;
use App\Module\Identity\Application\OwnerPasswordWriter;
use App\Module\Identity\Domain\PasswordHasher;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use PHPUnit\Framework\TestCase;

final class DefineInitialPasswordTest extends TestCase
{
    private const string VALID_PASSWORD = 'correct horse battery staple';

    public function testItHashesAndStoresThePasswordForAnOwnerWithoutOne(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $define = new DefineInitialPassword(
            new StubAuthenticationUserRepository(self::owner(hasPassword: false)),
            $writer,
            new PrefixPasswordHasher(),
        );

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));

        self::assertSame('00000000-0000-7000-8000-000000000001', $writer->userId);
        self::assertSame('hashed:'.self::VALID_PASSWORD, $writer->passwordHash);
    }

    public function testItRejectsAnOwnerThatAlreadyHasAPassword(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $define = new DefineInitialPassword(
            new StubAuthenticationUserRepository(self::owner(hasPassword: true)),
            $writer,
            new PrefixPasswordHasher(),
        );

        $this->expectException(InitialPasswordAlreadyDefined::class);

        try {
            $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
        } finally {
            self::assertNull($writer->userId, 'The writer must not be touched.');
        }
    }

    public function testItRejectsWhenNoOwnerHasBeenProvisioned(): void
    {
        $define = new DefineInitialPassword(
            new StubAuthenticationUserRepository(null),
            new RecordingOwnerPasswordWriter(applied: true),
            new PrefixPasswordHasher(),
        );

        $this->expectException(OwnerAccountNotProvisioned::class);

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
    }

    public function testItRejectsAPasswordThatBreaksThePolicyWithoutHashingOrWriting(): void
    {
        $writer = new RecordingOwnerPasswordWriter(applied: true);
        $hasher = new PrefixPasswordHasher();
        $define = new DefineInitialPassword(
            new StubAuthenticationUserRepository(self::owner(hasPassword: false)),
            $writer,
            $hasher,
        );

        $this->expectException(WeakPassword::class);

        try {
            $define(new DefineInitialPasswordInput('short'));
        } finally {
            self::assertSame(0, $hasher->calls);
            self::assertNull($writer->userId);
        }
    }

    public function testItReportsAConcurrentCallThatWonTheCompareAndSet(): void
    {
        $define = new DefineInitialPassword(
            new StubAuthenticationUserRepository(self::owner(hasPassword: false)),
            new RecordingOwnerPasswordWriter(applied: false),
            new PrefixPasswordHasher(),
        );

        $this->expectException(InitialPasswordAlreadyDefined::class);

        $define(new DefineInitialPasswordInput(self::VALID_PASSWORD));
    }

    private static function owner(bool $hasPassword): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: '00000000-0000-7000-8000-000000000001',
            email: 'owner@example.test',
            displayName: 'Owner',
            hasPassword: $hasPassword,
            disabledAt: null,
        );
    }
}

final class StubAuthenticationUserRepository implements AuthenticationUserRepository
{
    public function __construct(private readonly ?AuthenticatedUser $owner)
    {
    }

    public function findByEmail(string $email): ?AuthenticatedUser
    {
        return null !== $this->owner && 0 === strcasecmp($email, $this->owner->email) ? $this->owner : null;
    }

    public function findCredentialsByEmail(string $email): ?OwnerCredentials
    {
        $user = $this->findByEmail($email);

        return null === $user || !$user->hasPassword ? null : new OwnerCredentials($user->email, 'hash', null);
    }

    public function findProvisionedOwner(): ?AuthenticatedUser
    {
        return $this->owner;
    }
}

final class RecordingOwnerPasswordWriter implements OwnerPasswordWriter
{
    public ?string $userId = null;
    public ?string $passwordHash = null;

    public function __construct(private readonly bool $applied)
    {
    }

    public function storeInitialHash(string $userId, string $passwordHash): bool
    {
        $this->userId = $userId;
        $this->passwordHash = $passwordHash;

        return $this->applied;
    }
}

final class PrefixPasswordHasher implements PasswordHasher
{
    public int $calls = 0;

    public function hash(PlainPassword $password): string
    {
        ++$this->calls;

        return 'hashed:'.$password->value;
    }
}
