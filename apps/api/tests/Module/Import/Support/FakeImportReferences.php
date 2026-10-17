<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

use App\Module\Import\Application\ImportAccount;
use App\Module\Import\Application\ImportCategory;
use App\Module\Import\Application\ImportReferences;

/** The workspace facts of a planner test, held in memory so the plan is tested without a database. */
final class FakeImportReferences implements ImportReferences
{
    /** @var array<string, ImportAccount> */
    private array $accounts = [];

    /** @var array<string, ImportCategory> */
    private array $categories = [];

    /** @var array<string, string> */
    private array $instruments = [];

    /** @var list<string> */
    public array $closedMonths = [];

    /** @var array<string, true> */
    public array $accountsWithHistory = [];

    /** @var list<int> */
    public array $importedYears = [];

    public string $today = '2030-01-01';

    public function withAccount(string $label, string $mode = 'TRANSACTIONS', string $openedOn = '2020-01-01', string $asset = 'EUR'): self
    {
        $this->accounts[$label] = new ImportAccount('acc-'.$label, $mode, $asset, $openedOn);

        return $this;
    }

    public function withCategory(string $path, string $type, bool $hasDefaultAxes = false): self
    {
        $this->categories[$path] = new ImportCategory('cat-'.$path, $type, $hasDefaultAxes);

        return $this;
    }

    public function withInstrument(string $name): self
    {
        $this->instruments[$name] = 'ins-'.$name;

        return $this;
    }

    public function account(string $label): ?ImportAccount
    {
        return $this->accounts[$label] ?? null;
    }

    public function category(string $path): ?ImportCategory
    {
        return $this->categories[$path] ?? null;
    }

    public function instrument(string $name): ?string
    {
        return $this->instruments[$name] ?? null;
    }

    public function closedMonths(array $months): array
    {
        return array_values(array_intersect($months, $this->closedMonths));
    }

    public function hasTransactionBefore(string $accountId, \DateTimeImmutable $day): bool
    {
        return isset($this->accountsWithHistory[$accountId]);
    }

    public function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable($this->today, new \DateTimeZone('UTC'));
    }

    public function importedYears(): array
    {
        return $this->importedYears;
    }
}
