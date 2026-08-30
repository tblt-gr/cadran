<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Plants the double-submit CSRF cookie on a safe /api/ response whose request
 * did not already carry a valid one. The SPA reads it (it is deliberately not
 * HttpOnly) and echoes it back in the X-CSRF-TOKEN header on every mutation,
 * where {@see CsrfProtectionListener} checks the two against each other.
 *
 * SameSite=Strict keeps the cookie off cross-site requests entirely; the token
 * still expires on its own signature, so a stale tab is refused until it
 * reloads and its next session probe replants the cookie.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class CsrfCookieListener
{
    public function __construct(private SignedCsrfToken $token)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethodSafe() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        $current = $request->cookies->get(SignedCsrfToken::COOKIE_NAME);
        if (\is_string($current) && $this->token->isValid($current)) {
            return;
        }

        $event->getResponse()->headers->setCookie(Cookie::create(
            name: SignedCsrfToken::COOKIE_NAME,
            value: $this->token->issue(),
            expire: 0,
            path: '/',
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: Cookie::SAMESITE_STRICT,
        ));
    }
}
