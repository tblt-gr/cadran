<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Portfolio\Domain\OperationFilters;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;

final readonly class ListPortfolioOperations
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MAX_PER_PAGE = 100;
    public const int MAX_PAGE = 1000;
    public const int MAX_RANGE_YEARS = 10;
    public const int MAX_TYPES = 13;

    public function __construct(
        private CallerWorkspace $caller,
        private PortfolioAccountGate $gate,
        private PortfolioOperationRepository $operations,
        private PortfolioOperationPresenter $presenter,
    ) {
    }

    public function __invoke(string $accountId, ListPortfolioOperationsQuery $query): OperationPage
    {
        $workspace = $this->caller->resolve();
        $this->gate->forRead($workspace, $accountId);
        $filters = $this->filters($query);
        $page = self::bounded($query->page, 1, 1, self::MAX_PAGE);
        $perPage = self::bounded($query->perPage, self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE);

        $items = $this->operations->search($workspace, $accountId, $filters, $perPage, ($page - 1) * $perPage);

        return new OperationPage(
            $this->presenter->present($workspace, $items), $page, $perPage,
            $this->operations->count($workspace, $accountId, $filters),
        );
    }

    private function filters(ListPortfolioOperationsQuery $query): OperationFilters
    {
        $from = null === $query->from ? null : self::day($query->from);
        $to = null === $query->to ? null : self::day($query->to);
        if (null !== $from && null !== $to && ($to < $from || $to > $from->modify('+'.self::MAX_RANGE_YEARS.' years'))) {
            throw new InvalidOperationQuery('The date range is reversed or longer than ten years.');
        }
        if (count($query->types) > self::MAX_TYPES) {
            throw new InvalidOperationQuery('Too many operation types.');
        }
        $types = [];
        foreach ($query->types as $type) {
            $types[] = OperationType::tryFrom($type) ?? throw new InvalidOperationQuery('Unknown operation type.');
        }
        if (null !== $query->instrumentId && 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $query->instrumentId)) {
            throw new InvalidOperationQuery('The instrument filter is not an identifier.');
        }
        $includeVoided = match ($query->includeVoided) {
            null, '', 'false' => false,
            'true' => true,
            default => throw new InvalidOperationQuery('includeVoided is a boolean.'),
        };

        return new OperationFilters($from, $to, array_values(array_unique($types, SORT_REGULAR)), $query->instrumentId, $includeVoided);
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidOperationQuery('A date filter is an ISO 8601 calendar day.');
        }

        return $date;
    }

    private static function bounded(?string $literal, int $default, int $min, int $max): int
    {
        if (null === $literal || '' === $literal) {
            return $default;
        }
        if (1 !== preg_match('/^[0-9]{1,5}$/D', $literal) || (int) $literal < $min || (int) $literal > $max) {
            throw new InvalidOperationQuery('A pagination value is out of bounds.');
        }

        return (int) $literal;
    }
}
