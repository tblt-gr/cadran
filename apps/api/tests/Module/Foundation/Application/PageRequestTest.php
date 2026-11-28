<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Application;

use App\Module\Foundation\Application\PageRequest;
use PHPUnit\Framework\TestCase;

final class PageRequestTest extends TestCase
{
    public function testAnAbsentPageIsTheFirstOneAtTheDefaultSize(): void
    {
        $request = PageRequest::tryFrom(null, null, 50);

        self::assertNotNull($request);
        self::assertSame([1, 50, 0], [$request->page, $request->pageSize, $request->offset()]);
    }

    public function testTheCeilingsAreAccepted(): void
    {
        self::assertSame(99900, PageRequest::tryFrom(1000, 100, 50)?->offset());
        self::assertSame(40, PageRequest::tryFrom(3, 20, 50)?->offset());
    }

    public function testAValueOutsideItsBoundsIsRefused(): void
    {
        self::assertNull(PageRequest::tryFrom(1001, 10, 50));
        self::assertNull(PageRequest::tryFrom(0, 10, 50));
        self::assertNull(PageRequest::tryFrom(1, 101, 50));
        self::assertNull(PageRequest::tryFrom(1, 0, 50));
    }
}
