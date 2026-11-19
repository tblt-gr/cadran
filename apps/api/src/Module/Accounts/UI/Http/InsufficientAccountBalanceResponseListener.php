<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InsufficientAccountBalance;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The one HTTP answer to a write refused because it would put an account below zero.
 *
 * Every guarded use case raises the same exception, so the mapping lives here once.
 * The document carries the account, the input to blame and the first negative day,
 * never an amount.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 16)]
final readonly class InsufficientAccountBalanceResponseListener
{
    public const string TYPE = '/problems/account-balance-insufficient';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InsufficientAccountBalance || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $event->setResponse(ApiProblem::response(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans('api.problem.account_balance_insufficient.title'),
            $this->translator->trans('api.problem.account_balance_insufficient.detail', [
                '%date%' => $exception->firstNegativeOn->format('Y-m-d'),
            ]),
            self::TYPE,
            extensions: [
                'accountId' => $exception->accountId,
                'field' => $exception->field,
                'firstNegativeOn' => $exception->firstNegativeOn->format('Y-m-d'),
            ],
        ));
    }
}
