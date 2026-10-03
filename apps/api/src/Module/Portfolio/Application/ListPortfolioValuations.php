<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

final readonly class ListPortfolioValuations
{
    public const int DEFAULT_PER_PAGE = 24;
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ValuationRepository $valuations,
        private PortfolioValuationPresenter $presenter,
    ) {
    }

    public function __invoke(string $accountId, ?string $from, ?string $to, ?string $page, ?string $perPage): ValuationPage
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $this->gate->forRead($workspace, $accountId);
        $fromDay = null === $from ? null : self::day($from);
        $toDay = null === $to ? null : self::day($to);
        if (null !== $fromDay && null !== $toDay && $toDay < $fromDay) {
            throw new InvalidValuationQuery('The range ends before it starts.');
        }
        $pageNumber = self::integer($page, 1, 1, 1_000_000);
        $size = self::integer($perPage, self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE);

        $found = $this->valuations->search($workspace, $accountId, $fromDay, $toDay, $size, ($pageNumber - 1) * $size);

        return new ValuationPage(
            $this->presenter->present($workspace, $found),
            $pageNumber,
            $size,
            $this->valuations->count($workspace, $accountId, $fromDay, $toDay),
        );
    }

    private static function day(string $literal): \DateTimeImmutable
    {
        $date = 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $literal)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidValuationQuery('A date is a real ISO 8601 calendar day.');
        }

        return $date;
    }

    private static function integer(?string $literal, int $default, int $min, int $max): int
    {
        if (null === $literal) {
            return $default;
        }
        if (1 !== preg_match('/^[0-9]{1,7}$/D', $literal) || (int) $literal < $min || (int) $literal > $max) {
            throw new InvalidValuationQuery(sprintf('A page number or size is an integer from %d to %d.', $min, $max));
        }

        return (int) $literal;
    }
}
