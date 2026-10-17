<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\TransactionNature;

/** Whether an account holds a transaction, of any nature and not voided, booked before a day. A range too large to list counts as yes. */
final readonly class HasAccountTransactionBefore
{
    public function __construct(private ListAccountTransactionFacts $facts)
    {
    }

    public function __invoke(WorkspaceScope $workspace, string $accountId, \DateTimeImmutable $day): bool
    {
        try {
            $facts = ($this->facts)($workspace, $accountId, new \DateTimeImmutable('1900-01-01', new \DateTimeZone('UTC')), $day->modify('-1 day'), TransactionNature::cases());
        } catch (AccountTransactionScopeTooLarge) {
            return true;
        }

        foreach ($facts as $fact) {
            if (!$fact->voided) {
                return true;
            }
        }

        return false;
    }
}
