<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Infrastructure\Security\Http\AbsoluteSessionLifetime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * ASVS 5.0 V7.3.2 (absolute session timeout) and V7.4.1 (session termination
 * makes the session unusable), positive and negative.
 */
final class AbsoluteSessionLifetimeTest extends TestCase
{
    private const string NOW = '2026-11-30T12:00:00+00:00';
    private const int DAY = 86400;

    private int $now;

    protected function setUp(): void
    {
        $this->now = (new \DateTimeImmutable(self::NOW))->getTimestamp();
    }

    public function testARequestWithoutAPreviousSessionIsLeftAlone(): void
    {
        $session = $this->session(authenticatedAt: null);
        $request = Request::create('/api/v1/accounts');
        $request->setSession($session);

        $this->listener($this->silentLogger())->onKernelRequest($this->event($request));

        self::assertFalse($session->has(AbsoluteSessionLifetime::AUTHENTICATED_AT));
        self::assertTrue($session->has('_security_api'));
    }

    public function testAnAnonymousSessionIsNeverStampedNorInvalidated(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf', 'x');

        $this->listener($this->silentLogger())->onKernelRequest($this->event($this->requestWith($session)));

        self::assertFalse($session->has(AbsoluteSessionLifetime::AUTHENTICATED_AT));
        self::assertSame('x', $session->get('_csrf'));
    }

    public function testASessionWithoutASignInTimeIsStampedNowAndKeepsWorking(): void
    {
        $session = $this->session(authenticatedAt: null);

        $this->listener($this->silentLogger())->onKernelRequest($this->event($this->requestWith($session)));

        self::assertSame($this->now, $session->get(AbsoluteSessionLifetime::AUTHENTICATED_AT));
        self::assertTrue($session->has('_security_api'));
    }

    public function testASessionSignedInThirtyDaysAgoIsInvalidatedAndLogged(): void
    {
        $session = $this->session(authenticatedAt: $this->now - 30 * self::DAY);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('notice')->with('session_expired', self::identicalTo([]));

        $this->listener($logger)->onKernelRequest($this->event($this->requestWith($session)));

        self::assertFalse($session->has('_security_api'));
        self::assertFalse($session->has(AbsoluteSessionLifetime::AUTHENTICATED_AT));
    }

    public function testASessionSignedInTwentyNineDaysAgoKeepsWorkingAndIsNotRefreshed(): void
    {
        $signedInAt = $this->now - 29 * self::DAY;
        $session = $this->session(authenticatedAt: $signedInAt);

        $this->listener($this->silentLogger())->onKernelRequest($this->event($this->requestWith($session)));

        self::assertTrue($session->has('_security_api'));
        self::assertSame($signedInAt, $session->get(AbsoluteSessionLifetime::AUTHENTICATED_AT));
    }

    public function testOneSecondShortOfTheLimitStillWorks(): void
    {
        $session = $this->session(authenticatedAt: $this->now - AbsoluteSessionLifetime::MAX_AGE_SECONDS + 1);

        $this->listener($this->silentLogger())->onKernelRequest($this->event($this->requestWith($session)));

        self::assertTrue($session->has('_security_api'));
    }

    public function testASubRequestIsIgnored(): void
    {
        $session = $this->session(authenticatedAt: $this->now - 31 * self::DAY);
        $kernel = $this->createStub(KernelInterface::class);
        $event = new RequestEvent($kernel, $this->requestWith($session), HttpKernelInterface::SUB_REQUEST);

        $this->listener($this->silentLogger())->onKernelRequest($event);

        self::assertTrue($session->has('_security_api'));
    }

    private function session(?int $authenticatedAt): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_api', 'serialized-token');
        if (null !== $authenticatedAt) {
            $session->set(AbsoluteSessionLifetime::AUTHENTICATED_AT, $authenticatedAt);
        }

        return $session;
    }

    private function requestWith(Session $session): Request
    {
        $request = Request::create('/api/v1/accounts');
        $request->cookies->set($session->getName(), 'abc');
        $request->setSession($session);

        return $request;
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(KernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function listener(LoggerInterface $logger): AbsoluteSessionLifetime
    {
        return new AbsoluteSessionLifetime(new MockClock(self::NOW), $logger);
    }

    private function silentLogger(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method(self::anything());

        return $logger;
    }
}
