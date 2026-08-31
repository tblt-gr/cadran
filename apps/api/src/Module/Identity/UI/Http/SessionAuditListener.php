<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Identity\Application\RecordSessionEvent;
use App\Module\Identity\Application\SessionAuditIntent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the session audit event that the firewall handlers and the session
 * controller parked on the request, after the response has been sent.
 *
 * See {@see SessionAuditIntent} for why the write cannot stay on the response
 * path: it would leak account existence through latency, and a failed write
 * would break a sign-in or sign-out that has already happened.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final readonly class SessionAuditListener
{
    public function __construct(private RecordSessionEvent $recordSessionEvent)
    {
    }

    public function __invoke(TerminateEvent $event): void
    {
        $intent = $event->getRequest()->attributes->get(SessionAuditIntent::REQUEST_ATTRIBUTE);
        if ($intent instanceof SessionAuditIntent) {
            ($this->recordSessionEvent)($intent);
        }
    }
}
