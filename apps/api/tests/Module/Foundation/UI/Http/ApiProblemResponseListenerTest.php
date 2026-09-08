<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\UI\Http;

use App\Module\Foundation\Application\InvalidAmountInput;
use App\Module\Foundation\UI\Http\ApiProblemResponseListener;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiProblemResponseListenerTest extends KernelTestCase
{
    public function testAnInvalidAmountBecomesASafeMemberSpecificProblem(): void
    {
        $kernel = self::bootKernel();
        $listener = self::getContainer()->get(ApiProblemResponseListener::class);
        self::assertInstanceOf(ApiProblemResponseListener::class, $listener);

        $event = new ExceptionEvent(
            $kernel,
            Request::create('/api/v1/transactions'),
            HttpKernelInterface::MAIN_REQUEST,
            new InvalidAmountInput('/amount/value', 'amount.not_canonical'),
        );

        $listener($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->headers->get('content-type'));
        self::assertJsonStringEqualsJsonString(
            '{"type":"/problems/amount.not_canonical","title":"Montant invalide","status":422,"detail":"Le montant doit contenir une valeur décimale canonique et un code d’actif connus, dans les limites de précision autorisées.","pointer":"/amount/value"}',
            (string) $response->getContent(),
        );
    }
}
