<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\RequestStack;

/** Adds `extra.request_id` to every record emitted while a main request is being handled (not in console commands). */
#[AsMonologProcessor]
final readonly class RequestIdProcessor
{
    public function __construct(private RequestStack $requests)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $id = $this->requests->getMainRequest()?->attributes->get(RequestIdListener::ATTRIBUTE);
        if (!\is_string($id)) {
            return $record;
        }
        $record->extra['request_id'] = $id;

        return $record;
    }
}
