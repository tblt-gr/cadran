<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ends every authenticated session 30 days after the sign-in that created it,
 * whatever the activity. The 14-day idle ceiling alone lets a session cookie
 * copied from a lost device live as long as it keeps being used.
 *
 * The sign-in time lives only in the server-side session, so the client cannot
 * refresh it, and it is read from the server clock. An expired session is
 * invalidated (its row is deleted) and the request carries on unauthenticated,
 * so `access_control` answers the usual 401 problem document.
 */
final readonly class AbsoluteSessionLifetime
{
    public const int MAX_AGE_SECONDS = 2_592_000;
    public const string AUTHENTICATED_AT = '_cadran_authenticated_at';

    /** The firewall token key, for the `api` firewall. */
    private const string TOKEN_KEY = '_security_api';

    public function __construct(
        private ClockInterface $clock,
        #[Target('security')]
        private LoggerInterface $logger,
    ) {
    }

    // After the session listener (128), before the firewall (8).
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 9)]
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }

        $session = $request->getSession();
        if (!$session->has(self::TOKEN_KEY)) {
            return;
        }

        $now = $this->clock->now()->getTimestamp();
        $authenticatedAt = $session->get(self::AUTHENTICATED_AT);
        if (!\is_int($authenticatedAt)) {
            // A session opened before this lifetime existed: it ends 30 days from now.
            $session->set(self::AUTHENTICATED_AT, $now);

            return;
        }

        if ($now - $authenticatedAt >= self::MAX_AGE_SECONDS) {
            $session->invalidate();
            $this->logger->notice('session_expired');
        }
    }
}
