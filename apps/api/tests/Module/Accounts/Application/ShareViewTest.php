<?php

declare(strict_types=1);

namespace App\Tests\Module\Accounts\Application;

use App\Module\Accounts\Application\ShareView;
use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Foundation\Domain\DecimalValue;
use PHPUnit\Framework\TestCase;

/**
 * The published weight keeps its exact percent and a two-decimal display.
 *
 * A share is almost never a terminating decimal. Printing all 24 places on
 * an allocation bar would be unreadable, and rounding that figure in the
 * interface would move the presentation boundary out of the backend.
 */
final class ShareViewTest extends TestCase
{
    public function testTheDisplayPercentIsRoundedToTwoDecimalsAndTheExactOneStays(): void
    {
        $share = ShareView::fromShare(NetWorthShare::of(
            DecimalValue::fromString('0.0420080440935498286906'),
            DecimalValue::fromString('4.20080440935498286906'),
        ));

        self::assertSame('4.20080440935498286906', $share->percent);
        self::assertSame('4.20', $share->percentDisplay);
        self::assertNull($share->reason);
    }

    public function testAMissingShareHasNoDisplayPercentEither(): void
    {
        $share = ShareView::missingValuation();

        self::assertNull($share->percent);
        self::assertNull($share->percentDisplay);
        self::assertSame('MISSING_VALUATION', $share->reason);
    }
}
