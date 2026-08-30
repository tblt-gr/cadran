<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Infrastructure\Security\Http\LoginFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Translation\IdentityTranslator;

final class LoginFailureHandlerTest extends TestCase
{
    public function testThrottlingReportsRetryAfterFromTheLimiterThreshold(): void
    {
        $response = $this->handler()->onAuthenticationFailure(new Request(), new TooManyLoginAttemptsAuthenticationException(12));

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('720', $response->headers->get('Retry-After'));
    }

    public function testThrottlingWithoutAThresholdFallsBackToTheInterval(): void
    {
        $response = $this->handler()->onAuthenticationFailure(new Request(), new TooManyLoginAttemptsAuthenticationException());

        self::assertSame('900', $response->headers->get('Retry-After'));
    }

    public function testADisabledAccountIsAForbidden(): void
    {
        $response = $this->handler()->onAuthenticationFailure(new Request(), new CustomUserMessageAccountStatusException('disabled'));

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($response->headers->get('Retry-After'));
    }

    public function testAnyOtherFailureIsTheGeneric401(): void
    {
        $response = $this->handler()->onAuthenticationFailure(new Request(), new BadCredentialsException());

        self::assertSame(401, $response->getStatusCode());
    }

    private function handler(): LoginFailureHandler
    {
        return new LoginFailureHandler(new IdentityTranslator());
    }
}
