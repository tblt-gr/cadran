<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentStatus;

final readonly class ListInstruments
{
    public const int DEFAULT_PAGE_SIZE = 20;
    public const int MAX_PAGE_SIZE = 50;
    public const int MAX_PAGE = 1000;
    public const int MAX_QUERY_LENGTH = 60;

    public function __construct(private CallerWorkspace $caller, private InstrumentRepository $instruments)
    {
    }

    public function __invoke(?string $query, string $status, ?int $page, ?int $perPage, ?string $currencyCode = null): InstrumentPage
    {
        $page ??= 1;
        $perPage ??= self::DEFAULT_PAGE_SIZE;
        $query = null === $query || '' === trim($query) ? null : trim($query);
        $resolvedStatus = match ($status) {
            '', 'active' => InstrumentStatus::ACTIVE,
            'inactive' => InstrumentStatus::INACTIVE,
            'all' => null,
            default => throw new InvalidInstrumentInput('The instrument status filter is invalid.', 'invalid_instrument_query'),
        };
        if ($page < 1 || $page > self::MAX_PAGE || $perPage < 1 || $perPage > self::MAX_PAGE_SIZE || null !== $query && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new InvalidInstrumentInput('The instrument query is outside its bounds.', 'invalid_instrument_query');
        }
        try {
            $currency = null === $currencyCode ? null : AssetCode::fromString($currencyCode);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInstrumentInput('The instrument currency filter is invalid.', 'invalid_instrument_query', $exception);
        }
        $workspace = $this->caller->resolve();
        $items = $this->instruments->list($workspace, $resolvedStatus, $query, $currency, $perPage, ($page - 1) * $perPage);

        return new InstrumentPage(
            array_map(InstrumentView::fromInstrument(...), $items), $page, $perPage,
            $this->instruments->count($workspace, $resolvedStatus, $query, $currency),
        );
    }
}
