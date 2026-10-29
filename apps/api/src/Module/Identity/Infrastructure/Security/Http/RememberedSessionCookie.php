<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives the session cookie of a "remember me" login a 14-day lifetime, the same
 * ceiling as the server-side idle lifetime.
 *
 * Symfony's session listener writes the cookie itself from the configured
 * `cookie_lifetime` (0, a browser-session cookie), so the lifetime cannot be
 * changed from the session. This listener runs just after it and rewrites the
 * cookie it produced, only when the login asked to be remembered.
 */
final class RememberedSessionCookie
{
    public const string REQUEST_ATTRIBUTE = '_cadran_remember_session';
    public const int LIFETIME_SECONDS = 1209600;

    // One step after the session listener (-1000), which sets the cookie.
    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1001)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || true !== $request->attributes->get(self::REQUEST_ATTRIBUTE) || !$request->hasSession()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $name = $request->getSession()->getName();
        foreach ($headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                $headers->setCookie($cookie->withExpires(time() + self::LIFETIME_SECONDS));
            }
        }
    }
}
