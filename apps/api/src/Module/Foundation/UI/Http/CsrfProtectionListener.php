<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Defence-in-depth CSRF check at the /api/ boundary. It runs before the
 * firewall, so a forged request never reaches authentication, session handling
 * or login throttling, and a controller added later cannot opt out of it.
 *
 * An unsafe method must satisfy two independent checks:
 *  - the Origin / Sec-Fetch-Site headers, when the browser sends them, name this
 *    same origin;
 *  - the X-CSRF-TOKEN header carries a currently valid signed token and matches
 *    the csrf_token cookie byte for byte.
 *
 * The cookie is planted by {@see CsrfCookieListener} on a safe /api/ response,
 * which the SPA always fetches first.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final readonly class CsrfProtectionListener
{
    public function __construct(
        private SignedCsrfToken $token,
        private TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/') || $request->isMethodSafe()) {
            return;
        }

        if (!$this->sameOrigin($request) || !$this->tokenMatches($request)) {
            $event->setResponse($this->deny());
        }
    }

    private function sameOrigin(Request $request): bool
    {
        $expected = $request->getSchemeAndHttpHost();

        $origin = $request->headers->get('Origin');
        if (null !== $origin && $origin !== $expected) {
            return false;
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');

        return null === $fetchSite || \in_array($fetchSite, ['same-origin', 'none'], true);
    }

    private function tokenMatches(Request $request): bool
    {
        $header = $request->headers->get(SignedCsrfToken::HEADER_NAME);
        $cookie = $request->cookies->get(SignedCsrfToken::COOKIE_NAME);

        if (!\is_string($header) || '' === $header || !\is_string($cookie) || '' === $cookie) {
            return false;
        }

        return hash_equals($cookie, $header) && $this->token->isValid($header);
    }

    private function deny(): JsonResponse
    {
        return ApiProblem::response(
            403,
            $this->translator->trans('api.problem.csrf.title'),
            $this->translator->trans('api.problem.csrf.detail'),
            ApiProblem::TYPE_CSRF,
        );
    }
}
