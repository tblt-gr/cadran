<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\SignedCsrfToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SignedCsrfTokenTest extends TestCase
{
    private const string SECRET = 'test-only-secret';

    public function testAnEmptySecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SignedCsrfToken('', new MockClock());
    }

    public function testAFreshlyIssuedTokenIsValid(): void
    {
        $factory = new SignedCsrfToken(self::SECRET, new MockClock());

        self::assertTrue($factory->isValid($factory->issue()));
    }

    public function testTwoIssuedTokensDiffer(): void
    {
        $factory = new SignedCsrfToken(self::SECRET, new MockClock());

        self::assertNotSame($factory->issue(), $factory->issue());
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $factory = new SignedCsrfToken(self::SECRET, new MockClock());
        $token = $factory->issue();

        self::assertFalse($factory->isValid(substr($token, 0, -1).('0' === substr($token, -1) ? '1' : '0')));
    }

    public function testATokenSignedWithAnotherSecretIsRejected(): void
    {
        $issued = (new SignedCsrfToken('a-different-secret', new MockClock()))->issue();
        $verifier = new SignedCsrfToken(self::SECRET, new MockClock());

        self::assertFalse($verifier->isValid($issued));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $clock = new MockClock('2026-08-30 12:00:00');
        $factory = new SignedCsrfToken(self::SECRET, $clock);
        $token = $factory->issue();

        $clock->modify('+2 hours +1 second');

        self::assertFalse($factory->isValid($token));
    }

    public function testATokenStillInsideItsWindowIsAccepted(): void
    {
        $clock = new MockClock('2026-08-30 12:00:00');
        $factory = new SignedCsrfToken(self::SECRET, $clock);
        $token = $factory->issue();

        $clock->modify('+1 hour +59 minutes');

        self::assertTrue($factory->isValid($token));
    }

    public function testATokenIssuedInTheFutureIsRejected(): void
    {
        $clock = new MockClock('2026-08-30 12:00:00');
        $token = (new SignedCsrfToken(self::SECRET, $clock))->issue();

        $rewound = new MockClock('2026-08-30 11:00:00');

        self::assertFalse((new SignedCsrfToken(self::SECRET, $rewound))->isValid($token));
    }

    #[DataProvider('malformedTokens')]
    public function testAMalformedTokenIsRejected(string $token): void
    {
        $factory = new SignedCsrfToken(self::SECRET, new MockClock());

        self::assertFalse($factory->isValid($token));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'no separators' => ['abcdef'];
        yield 'too many parts' => ['a.1.b.c'];
        yield 'non-hex nonce' => ['zz.1700000000.'.hash_hmac('sha256', 'zz.1700000000', self::SECRET)];
        yield 'non-numeric timestamp' => ['ab.later.'.hash_hmac('sha256', 'ab.later', self::SECRET)];
    }
}
