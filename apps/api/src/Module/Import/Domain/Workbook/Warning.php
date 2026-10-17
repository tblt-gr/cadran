<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

/**
 * A stored value that was adjusted on the way in: it carried a binary float artifact
 * and was normalized under the tolerance, or it is a valuation with more decimals
 * than the asset precision and was rounded half-even to it. A cell the mapping
 * declares cached was read as its stored value, its formula never parsed.
 */
final readonly class Warning
{
    public const string FLOAT_ARTIFACT_NORMALIZED = 'FLOAT_ARTIFACT_NORMALIZED';
    public const string VALUE_ROUNDED = 'VALUE_ROUNDED';
    public const string CACHED_VALUE_USED = 'CACHED_VALUE_USED';

    public function __construct(
        public string $code,
        public string $sheet,
        public string $cell,
    ) {
    }
}
