<?php

declare(strict_types=1);

namespace App\Tests\Module\Identity\Infrastructure\Security\Http;

use App\Module\Identity\Infrastructure\Security\Http\LoginSuccessHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class LoginSuccessHandlerTest extends TestCase
{
    public function testARememberedLoginRenewsTheSessionWithAFourteenDayCookie(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::once())->method('migrate')->with(true, 1209600);

        $response = (new LoginSuccessHandler())->onAuthenticationSuccess($this->request('{"remember":true}', $session), $this->token());

        self::assertSame(204, $response->getStatusCode());
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
        $session = $this->createMock(SessionInterface::class);
        $session->expects(self::never())->method('migrate');

        $response = (new LoginSuccessHandler())->onAuthenticationSuccess($this->request($body, $session), $this->token());

        self::assertSame(204, $response->getStatusCode());
    }

    private function request(string $body, SessionInterface $session): Request
    {
        $request = Request::create('/api/v1/session', 'POST', content: $body);
        $request->setSession($session);

        return $request;
    }

    private function token(): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUserIdentifier')->willReturn('owner@example.test');

        return $token;
    }
}
