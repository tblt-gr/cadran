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
    public const int MAX_PAGE_SIZE = 100;
    public const int MAX_PAGE = 1000;
    public const int MAX_QUERY_LENGTH = 60;

    public function __construct(private CallerWorkspace $caller, private InstrumentRepository $instruments)
    {
    }

    public function __invoke(?string $query, string $status, ?int $page, ?int $pageSize, ?string $currencyCode = null, ?string $instrumentType = null): InstrumentPage
    {
        $page ??= 1;
        $pageSize ??= self::DEFAULT_PAGE_SIZE;
        $query = null === $query || '' === trim($query) ? null : trim($query);
        $resolvedStatus = match ($status) {
            '', 'active' => InstrumentStatus::ACTIVE,
            'inactive' => InstrumentStatus::INACTIVE,
            'all' => null,
            default => throw new InvalidInstrumentInput('The instrument status filter is invalid.', 'invalid_instrument_query'),
        };
        if ($page < 1 || $page > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE || null !== $query && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new InvalidInstrumentInput('The instrument query is outside its bounds.', 'invalid_instrument_query');
        }
        try {
            $currency = null === $currencyCode ? null : AssetCode::fromString($currencyCode);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInstrumentInput('The instrument currency filter is invalid.', 'invalid_instrument_query', $exception);
        }
        $type = null === $instrumentType ? null : (\App\Module\Portfolio\Domain\InstrumentType::tryFrom($instrumentType) ?? throw new InvalidInstrumentInput('The instrument type filter is invalid.', 'invalid_instrument_query'));
        $workspace = $this->caller->resolve();
        $items = $this->instruments->list($workspace, $resolvedStatus, $query, $currency, $pageSize, ($page - 1) * $pageSize, $type);

        return new InstrumentPage(
            array_map(InstrumentView::fromInstrument(...), $items), $page, $pageSize,
            $this->instruments->count($workspace, $resolvedStatus, $query, $currency, $type),
        );
    }
}
