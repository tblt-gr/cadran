<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\AccountRules;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\BusinessDay;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Foundation\Application\CallerWorkspace;
use Symfony\Component\Clock\ClockInterface;

/**
 * Resolves the ceilings, rates and terms in force for one account of the
 * calling workspace on a business date.
 *
 * The business date drives the whole read and the clock decides nothing but
 * how fresh each verification looks. Asking for 2023 answers with the rules
 * of 2023, including the ones since revised, which is what makes a past
 * statement re-readable instead of re-interpreted.
 */
final readonly class ReadAccountRules
{
    public function __construct(
        private CallerWorkspace $caller,
        private AccountRepository $accounts,
        private ProductCatalog $catalog,
        private ProductModelRepository $models,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, ?string $asOf): AccountRules
    {
        $today = BusinessDay::fromDateTime($this->clock->now());
        $businessDay = null === $asOf ? $today : self::businessDay($asOf);

        $workspace = $this->caller->resolve();
        $account = $this->accounts->find($workspace, $id);
        if (null === $account) {
            throw new AccountNotFound('No account carries this identifier in this workspace.');
        }

        if (null !== $account->productModelId) {
            // The model is never deleted, only archived, and archiving must
            // not change what an existing account resolves. A missing row
            // here would be a storage integrity failure, not a client error.
            $model = $this->models->find($workspace, $account->productModelId);
            if (null === $model) {
                throw new \LogicException('An account references a product model that no longer exists.');
            }

            return AccountRules::fromModel($account, $model->effectiveOn($businessDay->date));
        }

        $productCode = $account->productCode;
        if (null === $productCode) {
            return AccountRules::withoutProduct($account, $businessDay->date);
        }

        // The catalogue hides an archived product, while the account keeps the
        // reference it was created with. That combination has its own answer:
        // the account is intact and its rules are unreadable, which is not the
        // same thing as an account that never had any.
        $entry = $this->catalog->findByCode($productCode);
        if (null === $entry) {
            return AccountRules::withWithdrawnProduct($account, $productCode, $businessDay->date);
        }

        return AccountRules::fromProduct($account, $entry->effectiveOn($businessDay->date, $today->date));
    }

    private static function businessDay(string $asOf): BusinessDay
    {
        try {
            return BusinessDay::fromIsoDate($asOf);
        } catch (InvalidCatalogEntry $failure) {
            throw new InvalidAccountInput($failure->getMessage(), previous: $failure);
        }
    }
}
