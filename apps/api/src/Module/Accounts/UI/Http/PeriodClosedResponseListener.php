<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\PeriodClosed;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one HTTP answer to a refused write on a closed period.
 *
 * Every guarded use case raises the same exception, so the mapping lives here
 * once instead of being repeated, and possibly drifting, in each controller.
 * The problem names neither the month nor the operation.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class PeriodClosedResponseListener
{
    public const string TYPE = '/problems/period-closed';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof PeriodClosed || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse(ApiProblem::response(
            Response::HTTP_CONFLICT,
            $this->translator->trans('api.problem.period_closed.title'),
            $this->translator->trans('api.problem.period_closed.detail'),
            self::TYPE,
        ));
    }
}
