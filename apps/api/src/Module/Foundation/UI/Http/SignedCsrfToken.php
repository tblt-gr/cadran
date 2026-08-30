<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A stateless anti-CSRF token: a random nonce and an issue timestamp, signed
 * with the application secret. It carries its own expiry, so a tampered
 * ("invalid") or old ("expired") token is rejected without any server-side
 * store. Cross-site replay is stopped at transport: the token only travels in a
 * request header the browser refuses to set cross-origin, and the double-submit
 * cookie it must match is unreadable to another origin.
 */
final readonly class SignedCsrfToken
{
    public const string COOKIE_NAME = 'csrf_token';
    public const string HEADER_NAME = 'X-CSRF-TOKEN';

    /**
     * Two hours: long enough that a normal editing session never trips it, short
     * enough that a leaked token stops working the same day.
     */
    private const int TTL_SECONDS = 7200;

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $secret,
        private ClockInterface $clock,
    ) {
        if ('' === $secret) {
            // An empty key makes every signature forgeable by anyone who knows
            // the scheme, collapsing the token to a value an attacker can mint.
            throw new \InvalidArgumentException('APP_SECRET must not be empty.');
        }
    }

    public function issue(): string
    {
        $nonce = bin2hex(random_bytes(16));
        $issuedAt = $this->clock->now()->getTimestamp();
        $payload = $nonce.'.'.$issuedAt;

        return $payload.'.'.$this->sign($payload);
    }

    public function isValid(string $token): bool
    {
        $parts = explode('.', $token);
        if (3 !== \count($parts)) {
            return false;
        }

        [$nonce, $issuedAt, $signature] = $parts;
        if (!ctype_xdigit($nonce) || !ctype_digit($issuedAt)) {
            return false;
        }

        if (!hash_equals($this->sign($nonce.'.'.$issuedAt), $signature)) {
            return false;
        }

        $age = $this->clock->now()->getTimestamp() - (int) $issuedAt;

        return $age >= 0 && $age <= self::TTL_SECONDS;
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
