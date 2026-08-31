<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Application\IdentityAuditEvents;
use App\Module\Identity\Application\SessionAuditIntent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps a failed login to problem+json. A disabled account is reported distinctly
 * (403) because reaching that branch already required the correct password; any
 * other failure is the single generic 401, so a wrong password and an unknown
 * email cannot be told apart. Throttling answers 429 the same way regardless of
 * whether the submitted email exists. No credential or identifier is logged here.
 *
 * A failure against a known account is appended to that workspace's audit
 * trail; an attempt on an unknown email records nothing, because it belongs to
 * no workspace and its submitted string is attacker-controlled text. That
 * asymmetry is exactly why the write is deferred to kernel.terminate rather
 * than performed here: on the response path its latency would tell a caller
 * which addresses exist. See {@see SessionAuditIntent}.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    /** Used only if the exception carries no usable threshold. */
    private const int RETRY_AFTER_FALLBACK = 900;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if ($exception instanceof TooManyLoginAttemptsAuthenticationException) {
            // A throttled attempt never reached the credential check, and the
            // burst that triggered the throttle is already in the trail.
            return ApiProblemResponse::build(
                Response::HTTP_TOO_MANY_REQUESTS,
                $this->translator->trans('api.problem.too_many_attempts.title'),
                $this->translator->trans('api.problem.too_many_attempts.detail'),
                ['Retry-After' => (string) self::retryAfter($exception)],
            );
        }

        self::deferAudit($request);

        if ($exception instanceof AccountStatusException) {
            return ApiProblemResponse::build(
                Response::HTTP_FORBIDDEN,
                $this->translator->trans('api.problem.account_disabled.title'),
                $this->translator->trans('api.problem.account_disabled.detail'),
            );
        }

        return ApiProblemResponse::build(
            Response::HTTP_UNAUTHORIZED,
            $this->translator->trans('api.problem.invalid_credentials.title'),
            $this->translator->trans('api.problem.invalid_credentials.detail'),
        );
    }

    private static function deferAudit(Request $request): void
    {
        try {
            $email = $request->getPayload()->getString('email');
        } catch (\Throwable) {
            // A malformed body names no account; there is nothing to attribute.
            return;
        }

        if ('' !== $email) {
            $request->attributes->set(
                SessionAuditIntent::REQUEST_ATTRIBUTE,
                new SessionAuditIntent(IdentityAuditEvents::SIGN_IN_FAILED, $email),
            );
        }
    }

    /**
     * The limiter encodes minutes-until-clear into the exception threshold
     * (rounded up). Convert to seconds; a too-small value would invite a retry
     * that the sliding window then counts, pushing the block further out.
     */
    private static function retryAfter(TooManyLoginAttemptsAuthenticationException $exception): int
    {
        $minutes = $exception->getMessageData()['%minutes%'] ?? null;

        return \is_int($minutes) && $minutes > 0 ? $minutes * 60 : self::RETRY_AFTER_FALLBACK;
    }
}
