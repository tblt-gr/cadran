<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionNature;

/** Read-only facts of one account's transactions of the given natures within an inclusive booked-date range.
 *
 * @throws AccountTransactionScopeTooLarge when the range holds more than {@see AccountTransactionFactReader::LIMIT} facts
 */
final readonly class ListAccountTransactionFacts
{
    public function __construct(private AccountTransactionFactReader $facts)
    {
    }

    /**
     * @param list<TransactionNature> $natures
     *
     * @return list<AccountTransactionFact>
     */
    public function __invoke(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, array $natures): array
    {
        if ([] === $natures) {
            return [];
        }
        $facts = $this->facts->between($workspace, $accountId, $from, $to, $natures);
        if (count($facts) > AccountTransactionFactReader::LIMIT) {
            throw new AccountTransactionScopeTooLarge('The range holds too many transactions.');
        }

        return $facts;
    }
}
