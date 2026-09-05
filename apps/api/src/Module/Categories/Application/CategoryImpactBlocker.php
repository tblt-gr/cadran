<?php

declare(strict_types=1);

namespace App\Module\Categories\Application;

/**
 * Why an operation would be refused. A preview reports the whole list so the
 * interface can explain every reason at once instead of revealing them one
 * failed submission at a time.
 */
enum CategoryImpactBlocker: string
{
    case ACTIVE_CHILDREN = 'ACTIVE_CHILDREN';
    case ALREADY_REDIRECTED = 'ALREADY_REDIRECTED';
    case CYCLE = 'CYCLE';
    case DEPTH_EXCEEDED = 'DEPTH_EXCEEDED';
    case REDIRECTION_TARGET = 'REDIRECTION_TARGET';
    case REPLACEMENT_CHAIN_TOO_LONG = 'REPLACEMENT_CHAIN_TOO_LONG';
    case REPLACEMENT_CYCLE = 'REPLACEMENT_CYCLE';
    case SIBLING_LABEL_CONFLICT = 'SIBLING_LABEL_CONFLICT';
    case SOURCE_ARCHIVED = 'SOURCE_ARCHIVED';
    case TARGET_ARCHIVED = 'TARGET_ARCHIVED';
    case TARGET_IS_DESCENDANT = 'TARGET_IS_DESCENDANT';
    case TARGET_IS_SELF = 'TARGET_IS_SELF';
    case TARGET_MISSING = 'TARGET_MISSING';
    case TARGET_NOT_FOUND = 'TARGET_NOT_FOUND';
    case TARGET_TYPE_MISMATCH = 'TARGET_TYPE_MISMATCH';
}
