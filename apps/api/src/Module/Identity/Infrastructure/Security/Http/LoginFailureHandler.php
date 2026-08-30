<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps a failed login to problem+json. A disabled account is reported distinctly
 * (403) because reaching that branch already required the correct password; any
 * other failure is the single generic 401, so a wrong password and an unknown
 * email cannot be told apart. No credential or identifier is logged here.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
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
}
