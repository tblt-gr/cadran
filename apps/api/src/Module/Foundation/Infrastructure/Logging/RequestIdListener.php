<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Gives each main request a server-generated identifier and echoes it on the response, so the log lines of a
 * failed request can be found. An incoming X-Request-Id is ignored: trusting it would let a client forge or
 * inject content into the logs.
 */
final readonly class RequestIdListener
{
    public const string ATTRIBUTE = '_request_id';
    public const string HEADER = 'X-Request-Id';

    public function __construct(private UuidGenerator $uuids)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 4096)]
    public function assign(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->attributes->set(self::ATTRIBUTE, $this->uuids->generate());
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -2048)]
    public function expose(ResponseEvent $event): void
    {
        $id = $event->getRequest()->attributes->get(self::ATTRIBUTE);
        if ($event->isMainRequest() && \is_string($id)) {
            $event->getResponse()->headers->set(self::HEADER, $id);
        }
    }
}
