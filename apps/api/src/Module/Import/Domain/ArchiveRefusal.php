<?php

declare(strict_types=1);

namespace App\Module\Import\Domain;

/** The control that refused an archive; printed as a code, never with content. */
enum ArchiveRefusal: string
{
    case NOT_AN_ARCHIVE = 'NOT_AN_ARCHIVE';
    case FILE_TOO_LARGE = 'FILE_TOO_LARGE';
    case TOO_MANY_ENTRIES = 'TOO_MANY_ENTRIES';
    case ENTRY_TOO_LARGE = 'ENTRY_TOO_LARGE';
    case COMPRESSION_RATIO = 'COMPRESSION_RATIO';
    case UNSAFE_ENTRY_NAME = 'UNSAFE_ENTRY_NAME';
    case UNSAFE_RELATIONSHIP = 'UNSAFE_RELATIONSHIP';
    case DOCTYPE_OR_ENTITY = 'DOCTYPE_OR_ENTITY';
    case UNSUPPORTED_ENCODING = 'UNSUPPORTED_ENCODING';
    case MISSING_PART = 'MISSING_PART';
    case MALFORMED_XML = 'MALFORMED_XML';
    case UNSUPPORTED_CONTENT_TYPE = 'UNSUPPORTED_CONTENT_TYPE';
    case TOO_MANY_CELLS = 'TOO_MANY_CELLS';
}
