<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/** How the effective color of a group is obtained. */
enum AccountGroupColorMode: string
{
    /** Palette default for a root, shade of the parent for a sub-group. */
    case AUTO = 'AUTO';
    /** The parent's effective color, unchanged. */
    case PARENT = 'PARENT';
    /** The explicit color stored on the group. */
    case CUSTOM = 'CUSTOM';
}
