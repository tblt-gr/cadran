<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\SessionAuditIntent;
use App\Module\Identity\Infrastructure\Security\Http\LoginFailureHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Translation\IdentityTranslator;

final class LoginFailureHandlerTest extends TestCase
{
    private const string EMAIL = 'owner@example.test';

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

    public function testAFailureParksAnAuditIntentInsteadOfWritingOnTheResponsePath(): void
    {
        // Writing here would only happen for an email that matches an account,
        // so its latency would tell a caller which addresses exist. The intent
        // is drained by SessionAuditListener on kernel.terminate.
        $request = self::loginRequest(self::EMAIL);

        $this->handler()->onAuthenticationFailure($request, new BadCredentialsException());

        $intent = $request->attributes->get(SessionAuditIntent::REQUEST_ATTRIBUTE);
        self::assertInstanceOf(SessionAuditIntent::class, $intent);
        self::assertSame(IdentityAuditEvents::SIGN_IN_FAILED, $intent->eventType);
        self::assertSame(self::EMAIL, $intent->email);
    }

    public function testADisabledAccountFailureIsStillAudited(): void
    {
        $request = self::loginRequest(self::EMAIL);

        $this->handler()->onAuthenticationFailure($request, new CustomUserMessageAccountStatusException('disabled'));

        self::assertInstanceOf(
            SessionAuditIntent::class,
            $request->attributes->get(SessionAuditIntent::REQUEST_ATTRIBUTE),
        );
    }

    public function testAThrottledAttemptParksNothing(): void
    {
        // The burst that armed the limiter is already in the trail; counting
        // the refusals on top would let an attacker grow the table at will.
        $request = self::loginRequest(self::EMAIL);

        $this->handler()->onAuthenticationFailure($request, new TooManyLoginAttemptsAuthenticationException(12));

        self::assertNull($request->attributes->get(SessionAuditIntent::REQUEST_ATTRIBUTE));
    }

    public function testAMalformedBodyParksNothing(): void
    {
        $request = Request::create('/api/v1/session', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: '{"email":');

        $response = $this->handler()->onAuthenticationFailure($request, new BadCredentialsException());

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($request->attributes->get(SessionAuditIntent::REQUEST_ATTRIBUTE));
    }

    private static function loginRequest(string $email): Request
    {
        return Request::create(
            '/api/v1/session',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['email' => $email, 'password' => 'whatever value here']),
        );
    }

    private function handler(): LoginFailureHandler
    {
        return new LoginFailureHandler(new IdentityTranslator());
    }
}
