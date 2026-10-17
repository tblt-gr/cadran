<?php

declare(strict_types=1);

namespace App\Module\Import\Infrastructure\Workspace;

use App\Module\Accounts\Application\AccountView;
use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\ListAccounts;
use App\Module\Categories\Application\CategoryView;
use App\Module\Categories\Application\ListCategories;
use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Application\ImportCategory;
use App\Module\Import\Application\ImportReferences;
use App\Module\Portfolio\Application\InstrumentView;
use App\Module\Portfolio\Application\ListInstruments;
use App\Module\Transactions\Application\HasAccountTransactionBefore;
use App\Module\Transactions\Application\ReadImportedWorkbookYears;

/**
 * The workspace facts of the planner, read through the public application
 * services of Accounts, Categories, Portfolio and Transactions, so every answer
 * is scoped to the workspace of the caller. The lists are read once: one
 * instance serves one import run.
 */
final class ServiceImportReferences implements ImportReferences
{
    private const int PAGE_SIZE = 100;

    /** @var array<string, list<AccountView>>|null */
    private ?array $accounts = null;

    /** @var array<string, ImportCategory|null>|null */
    private ?array $categories = null;

    /** @var array<string, string>|null */
    private ?array $instruments = null;

    public function __construct(
        private readonly CallerWorkspace $caller,
        private readonly ListAccounts $listAccounts,
        private readonly ListCategories $listCategories,
        private readonly ListInstruments $listInstruments,
        private readonly AssertPeriodOpen $periods,
        private readonly HasAccountTransactionBefore $transactionBefore,
        private readonly WorkspaceCalendar $calendar,
        private readonly ReadImportedWorkbookYears $importedYears,
    ) {
    }

    public function account(string $label): ?ImportAccount
    {
        $matches = $this->accountsByLabel()[$label] ?? [];
        if (1 !== count($matches)) {
            return null;
        }
        $account = $matches[0];

        return new ImportAccount($account->id, $account->valuationMode, $account->assetCode, $account->openedOn);
    }

    public function category(string $path): ?ImportCategory
    {
        return $this->categoriesByPath()[$path] ?? null;
    }

    public function instrument(string $name): ?string
    {
        return $this->instrumentsByName()[$name] ?? null;
    }

    public function closedMonths(array $months): array
    {
        $days = array_map(static fn (string $month): \DateTimeImmutable => new \DateTimeImmutable($month.'-01', new \DateTimeZone('UTC')), $months);

        return array_values(array_filter($months, fn (string $month): bool => isset($this->periods->closedMonthKeys($this->caller->resolve(), $days)[$month])));
    }

    public function hasTransactionBefore(string $accountId, \DateTimeImmutable $day): bool
    {
        return ($this->transactionBefore)($this->caller->resolve(), $accountId, $day);
    }

    public function today(): \DateTimeImmutable
    {
        return $this->calendar->today();
    }

    public function importedYears(): array
    {
        return ($this->importedYears)($this->caller->resolve());
    }

    /**
     * @return array<string, list<AccountView>>
     */
    private function accountsByLabel(): array
    {
        if (null !== $this->accounts) {
            return $this->accounts;
        }
        $byLabel = [];
        for ($page = 1; $page <= ListAccounts::MAX_PAGE; ++$page) {
            $result = ($this->listAccounts)(false, false, $page, self::PAGE_SIZE);
            foreach ($result->items as $account) {
                $byLabel[$account->label][] = $account;
            }
            if ($page * self::PAGE_SIZE >= $result->total) {
                break;
            }
        }

        return $this->accounts = $byLabel;
    }

    /**
     * @return array<string, ImportCategory|null> by path from the root; a path two categories share maps to null
     */
    private function categoriesByPath(): array
    {
        if (null !== $this->categories) {
            return $this->categories;
        }
        /** @var array<string, CategoryView> $views */
        $views = [];
        for ($page = 1; $page <= ListCategories::MAX_PAGE; ++$page) {
            $result = ($this->listCategories)(false, $page, self::PAGE_SIZE);
            foreach ($result->items as $category) {
                $views[$category->id] = $category;
            }
            if ($page * self::PAGE_SIZE >= $result->total) {
                break;
            }
        }

        $paths = [];
        foreach ($views as $category) {
            $path = self::pathOf($category, $views);
            if (null === $path) {
                continue;
            }
            $paths[$path] = array_key_exists($path, $paths)
                ? null
                : new ImportCategory($category->id, $category->type, [] !== $category->defaultAnalyticAxes);
        }

        return $this->categories = $paths;
    }

    /**
     * @param array<string, CategoryView> $views
     */
    private static function pathOf(CategoryView $category, array $views): ?string
    {
        $labels = [$category->label];
        for ($parentId = $category->parentId; null !== $parentId; $parentId = $parent->parentId) {
            $parent = $views[$parentId] ?? null;
            if (null === $parent || count($labels) > 16) {
                // The parent is archived, or the chain is not a tree: no path names this category.
                return null;
            }
            array_unshift($labels, $parent->label);
        }

        return implode('/', $labels);
    }

    /**
     * @return array<string, string>
     */
    private function instrumentsByName(): array
    {
        if (null !== $this->instruments) {
            return $this->instruments;
        }
        $byName = [];
        for ($page = 1; $page <= ListInstruments::MAX_PAGE; ++$page) {
            $result = ($this->listInstruments)(null, 'active', $page, self::PAGE_SIZE);
            foreach ($result->items as $instrument) {
                /* @var InstrumentView $instrument */
                $byName[$instrument->name] ??= $instrument->id;
            }
            if ($page * self::PAGE_SIZE >= $result->total) {
                break;
            }
        }

        return $this->instruments = $byName;
    }
}
