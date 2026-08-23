<?php

declare(strict_types=1);

namespace App\Tests\Module\Foundation\Application;

use App\Module\Foundation\Application\GetFoundationStatus;
use PHPUnit\Framework\TestCase;

final class GetFoundationStatusTest extends TestCase
{
    public function testItReturnsTheVersionedFoundationStatus(): void
    {
        $status = (new GetFoundationStatus())();

        self::assertSame('ready', $status->status);
        self::assertSame('v1', $status->apiVersion);
    }
}
