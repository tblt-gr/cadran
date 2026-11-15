<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Infrastructure\Logging;

use App\Tests\Support\LogCapture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

final class RequestIdListenerTest extends WebTestCase
{
    private const string UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testASuccessfulResponseCarriesAServerGeneratedRequestId(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/status');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression(self::UUID_V7, $this->requestId($client->getResponse()->headers->get('X-Request-Id')));
    }

    public function testAProblemResponseCarriesTheHeader(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/v1/no-such-route');

        self::assertResponseStatusCodeSame(404);
        self::assertMatchesRegularExpression(self::UUID_V7, $this->requestId($client->getResponse()->headers->get('X-Request-Id')));
    }

    public function testAnExceptionPathCarriesTheHeaderAndItsLogLinesShareTheId(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $capture = LogCapture::attach(self::getContainer()->get('logger'));
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener('kernel.request', static function (RequestEvent $event): void {
            if ('/api/v1/status' === $event->getRequest()->getPathInfo()) {
                throw new \RuntimeException('Zx9-boom');
            }
        }, 1000);

        $client->request('GET', '/api/v1/status');

        self::assertResponseStatusCodeSame(500);
        $id = $this->requestId($client->getResponse()->headers->get('X-Request-Id'));
        $matching = array_filter($capture->records(), static fn (array $record): bool => is_array($record['data']['extra'] ?? null) && ($record['data']['extra']['request_id'] ?? null) === $id);
        self::assertNotEmpty($matching, 'The 500 must be logged with the request id of its response.');
        self::assertFalse($capture->contains('Zx9-boom'), 'The exception message must stay out of the logs.');
    }

    public function testAnIncomingRequestIdIsIgnoredAndNeverLogged(): void
    {
        $client = self::createClient();
        $capture = LogCapture::attach(self::getContainer()->get('logger'));
        $client->request('GET', '/api/v1/no-such-route', server: ['HTTP_X_REQUEST_ID' => "forged\nid-Zx9-injected"]);

        $id = $this->requestId($client->getResponse()->headers->get('X-Request-Id'));
        self::assertMatchesRegularExpression(self::UUID_V7, $id);
        self::assertFalse($capture->contains('Zx9-injected'));
    }

    private function requestId(?string $header): string
    {
        self::assertNotNull($header, 'X-Request-Id is missing.');

        return $header;
    }
}
