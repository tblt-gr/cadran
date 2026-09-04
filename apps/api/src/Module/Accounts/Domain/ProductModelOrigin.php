<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * Where a workspace product model came from.
 *
 * A model copied from the system catalogue and one typed by hand carry the
 * same figures and deserve different trust: the first can be held against the
 * publication it was copied from, the second answers only for itself. Stating
 * the origin is what keeps a duplicate from inheriting the authority of a
 * source it no longer tracks.
 */
enum ProductModelOrigin: string
{
    /** Described by the workspace, with no model behind it. */
    case DECLARED = 'DECLARED';
    /** Copied from a system catalogue product, then owned by the workspace. */
    case SYSTEM_PRODUCT = 'SYSTEM_PRODUCT';
    /** Copied from another model of the same workspace. */
    case WORKSPACE_MODEL = 'WORKSPACE_MODEL';
}
