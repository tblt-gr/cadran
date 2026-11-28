<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Reporting\Application\MonthFigures;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use PHPUnit\Framework\TestCase;

final class MonthFiguresTest extends TestCase
{
    public function testAProjectionWithoutAPolicyVersionIsRefusedRatherThanReadAsVersionOne(): void
    {
        $this->expectException(\LogicException::class);

        MonthFigures::fromProjection(MonthlyProjectionFixture::view('2025-01', policyVersion: null));
    }

    public function testTheResolvedVersionIsKeptEvenWithoutItsDefinition(): void
    {
        $figures = MonthFigures::fromProjection(MonthlyProjectionFixture::view('2025-01', policyVersion: 7, policyLabel: null));

        self::assertSame(7, $figures->policyVersion);
    }
}
