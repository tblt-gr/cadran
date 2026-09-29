<?php

declare(strict_types=1);

namespace App\Module\Budget\Application\EmergencyFund;

/** The workspace holds more accounts, categories or monthly movements than one coverage read may scan. */
final class EmergencyFundScopeTooLarge extends \RuntimeException
{
}
