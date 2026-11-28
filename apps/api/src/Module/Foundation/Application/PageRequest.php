<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

/** One page of a list, already inside the ceilings every list endpoint shares. */
final readonly class PageRequest
{
    /** Caps the offset, so a page number cannot make PostgreSQL walk the table. */
    public const int MAX_PAGE = 1000;
    public const int MAX_PAGE_SIZE = 100;

    private function __construct(public int $page, public int $pageSize)
    {
    }

    /** `null` when a value is below 1 or above its ceiling, so each caller raises its own refusal. */
    public static function tryFrom(?int $page, ?int $pageSize, int $defaultPageSize): ?self
    {
        $page ??= 1;
        $pageSize ??= $defaultPageSize;
        if ($page < 1 || $page > self::MAX_PAGE || $pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            return null;
        }

        return new self($page, $pageSize);
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->pageSize;
    }
}
