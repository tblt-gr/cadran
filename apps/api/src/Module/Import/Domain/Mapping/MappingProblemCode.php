<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

enum MappingProblemCode: string
{
    case YAML_SYNTAX = 'YAML_SYNTAX';
    case DUPLICATE_KEY = 'DUPLICATE_KEY';
    case NOT_A_MAP = 'NOT_A_MAP';
    case UNKNOWN_KEY = 'UNKNOWN_KEY';
    case UNSUPPORTED_VERSION = 'UNSUPPORTED_VERSION';
    case INVALID_VALUE = 'INVALID_VALUE';
    case INVALID_CATEGORY_PATH = 'INVALID_CATEGORY_PATH';
    case INVALID_INSTRUMENT = 'INVALID_INSTRUMENT';
    case DUPLICATE_LABEL = 'DUPLICATE_LABEL';
    case EMPTY_LABEL = 'EMPTY_LABEL';
    case TOO_MANY_ENTRIES = 'TOO_MANY_ENTRIES';
}
