<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Domain;

use App\Module\Identity\Domain\CommonPasswordList;
use App\Module\Identity\Domain\PlainPassword;
use App\Module\Identity\Domain\WeakPassword;
use PHPUnit\Framework\TestCase;

final class PlainPasswordTest extends TestCase
{
    public function testItAcceptsAPasswordWithinThePolicyLength(): void
    {
        $password = PlainPassword::fromString('correct horse battery');

        self::assertSame('correct horse battery', $password->value);
    }

    public function testItAcceptsTheBoundaryLengths(): void
    {
        self::assertSame(12, mb_strlen(PlainPassword::fromString(str_repeat('a', 12))->value));
        self::assertSame(128, mb_strlen(PlainPassword::fromString(str_repeat('a', 128))->value));
    }

    public function testItRejectsATooShortPassword(): void
    {
        $this->expectException(WeakPassword::class);

        PlainPassword::fromString(str_repeat('a', 11));
    }

    public function testItRejectsATooLongPassword(): void
    {
        $this->expectException(WeakPassword::class);

        PlainPassword::fromString(str_repeat('a', 129));
    }

    public function testItCountsUnicodeCodePointsNotBytes(): void
    {
        // 12 code points, 24 bytes in UTF-8: the policy is about characters.
        $password = PlainPassword::fromString(str_repeat('é', 12));

        self::assertSame(12, mb_strlen($password->value));
    }

    public function testItDoesNotLeakTheCandidateValueInTheError(): void
    {
        try {
            PlainPassword::fromString('short');
            self::fail('Expected a WeakPassword exception.');
        } catch (WeakPassword $exception) {
            self::assertStringNotContainsString('short', $exception->getMessage());
        }
    }

    public function testAChosenPasswordMustNotBeOnTheCommonList(): void
    {
        $common = new class implements CommonPasswordList {
            public function contains(string $candidate): bool
            {
                return 'a common passphrase' === mb_strtolower($candidate);
            }
        };

        self::assertSame('an uncommon passphrase', PlainPassword::chosen('an uncommon passphrase', $common)->value);
        $this->expectException(WeakPassword::class);

        PlainPassword::chosen('A Common Passphrase', $common);
    }

    public function testAChosenPasswordStillObeysTheLengthPolicy(): void
    {
        $nothingIsCommon = new class implements CommonPasswordList {
            public function contains(string $candidate): bool
            {
                return false;
            }
        };
        $this->expectException(WeakPassword::class);

        PlainPassword::chosen('short', $nothingIsCommon);
    }
}
