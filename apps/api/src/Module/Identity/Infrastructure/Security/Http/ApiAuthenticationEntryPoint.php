<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Reached when an unauthenticated request hits a protected API route. Answers
 * with problem+json instead of redirecting to an HTML login page.
 */
final readonly class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiProblemResponse::build(
            Response::HTTP_UNAUTHORIZED,
            $this->translator->trans('api.problem.unauthorized.title'),
            $this->translator->trans('api.problem.unauthorized.detail'),
        );
    }
}
