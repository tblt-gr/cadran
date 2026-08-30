<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\UI\Http\CsrfProtectionListener;
use App\Module\Foundation\UI\Http\SignedCsrfToken;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The guard keys on the path prefix and the method, never on a named route, so
 * a controller added later under /api/ is covered without opting in.
 */
final class CsrfProtectionListenerTest extends TestCase
{
    private SignedCsrfToken $token;
    private CsrfProtectionListener $listener;

    protected function setUp(): void
    {
        $this->token = new SignedCsrfToken('test-secret', new MockClock());
        $this->listener = new CsrfProtectionListener($this->token, new IdentityTranslator());
    }

    public function testAnUnknownApiRouteStillRequiresACsrfToken(): void
    {
        $event = $this->event(Request::create('/api/v1/anything-new', 'POST'));

        ($this->listener)($event);

        self::assertNotNull($event->getResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
    }

    public function testASafeMethodIsNeverChallenged(): void
    {
        $event = $this->event(Request::create('/api/v1/anything-new', 'GET'));

        ($this->listener)($event);

        self::assertNull($event->getResponse());
    }

    public function testANonApiPathIsIgnored(): void
    {
        $event = $this->event(Request::create('/health', 'POST'));

        ($this->listener)($event);

        self::assertNull($event->getResponse());
    }

    public function testAMatchingHeaderAndCookiePass(): void
    {
        $value = $this->token->issue();
        $request = Request::create('/api/v1/anything-new', 'POST');
        $request->headers->set(SignedCsrfToken::HEADER_NAME, $value);
        $request->cookies->set(SignedCsrfToken::COOKIE_NAME, $value);

        $event = $this->event($request);
        ($this->listener)($event);

        self::assertNull($event->getResponse());
    }

    private function event(Request $request): RequestEvent
    {
        $kernel = $this->createStub(HttpKernelInterface::class);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
