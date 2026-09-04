<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

/**
 * The model changed between the read the caller acted on and their write. It
 * is answered apart from a name conflict: one asks for another name, the other
 * for a reload.
 */
final class StaleProductModelVersion extends \RuntimeException
{
}
