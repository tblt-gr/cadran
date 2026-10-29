<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Infrastructure\Security\Http\LoginSuccessHandler;
use App\Module\Identity\Infrastructure\Security\Http\RememberedSessionCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class LoginSuccessHandlerTest extends TestCase
{
    public function testARememberedLoginFlagsTheRequestSoTheCookieGetsALifetime(): void
    {
        $request = $this->request('{"remember":true}');

        $response = (new LoginSuccessHandler())->onAuthenticationSuccess($request, $this->token());

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($request->attributes->get(RememberedSessionCookie::REQUEST_ATTRIBUTE));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatDoNotOptIn(): iterable
    {
        yield 'absent' => ['{"email":"a@b.test"}'];
        yield 'false' => ['{"remember":false}'];
        yield 'truthy string' => ['{"remember":"true"}'];
        yield 'one' => ['{"remember":1}'];
    }

    #[DataProvider('bodiesThatDoNotOptIn')]
    public function testAnyOtherLoginKeepsTheBrowserSessionCookie(string $body): void
    {
        $request = $this->request($body);

        $response = (new LoginSuccessHandler())->onAuthenticationSuccess($request, $this->token());

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse($request->attributes->has(RememberedSessionCookie::REQUEST_ATTRIBUTE));
    }

    private function request(string $body): Request
    {
        return Request::create('/api/v1/session', 'POST', content: $body);
    }

    private function token(): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('owner@example.test');

        return $token;
    }
}
