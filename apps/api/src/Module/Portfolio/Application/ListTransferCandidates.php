<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Transactions\Application\ListAccountTransferLegs;

/** The booked transfer legs a deposit or a withdrawal could mirror, minus those an active operation already backs. */
final readonly class ListTransferCandidates
{
    public const int LIMIT = 50;
    /** Legs read before the already-linked ones are dropped, so a long history of linked legs cannot empty the list. */
    private const int SCAN = 500;

    public function __construct(
        private CallerWorkspace $caller,
        private PortfolioAccountGate $gate,
        private ListAccountTransferLegs $legs,
        private PortfolioOperationRepository $operations,
    ) {
    }

    /** @return list<TransferCandidateView> */
    public function __invoke(string $accountId, string $direction): array
    {
        if ('IN' !== $direction && 'OUT' !== $direction) {
            throw new InvalidOperationQuery('The direction is IN or OUT.');
        }
        $workspace = $this->caller->resolve();
        $this->gate->forRead($workspace, $accountId);

        $legs = ($this->legs)($workspace, $accountId, $direction, self::SCAN);
        $linked = array_flip($this->operations->linkedAmong($workspace, array_map(static fn ($leg): string => $leg->id, $legs)));
        $candidates = [];
        foreach ($legs as $leg) {
            if (isset($linked[$leg->id])) {
                continue;
            }
            $candidates[] = new TransferCandidateView(
                $leg->id, $leg->bookedOn->format('Y-m-d'),
                ['value' => ExactDecimal::absolute($leg->amount->value)->toString(), 'assetCode' => $leg->amount->asset->toString()],
                $leg->label,
            );
            if (count($candidates) >= self::LIMIT) {
                break;
            }
        }

        return $candidates;
    }
}
