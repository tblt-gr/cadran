<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** An ordering move naming a non-member or a self anchor; shared by accounts and groups. */
final class InvalidAccountMove extends \DomainException
{
}
