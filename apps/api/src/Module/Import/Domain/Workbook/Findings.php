<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Issue;
use App\Module\Import\Domain\ReasonCode;

/** The issues and warnings collected while one workbook is read, in reading order. */
final class Findings
{
    /** @var list<Issue> */
    private array $issues = [];

    /** @var list<Warning> */
    private array $warnings = [];

    public function add(ReasonCode $code, ?string $sheet, ?string $cell): void
    {
        $this->issues[] = new Issue($code, $sheet, $cell);
    }

    public function warnFloatArtifact(string $sheet, string $cell): void
    {
        $this->warnings[] = new Warning(Warning::FLOAT_ARTIFACT_NORMALIZED, $sheet, $cell);
    }

    public function warnCachedValueUsed(string $sheet, string $cell): void
    {
        $this->warnings[] = new Warning(Warning::CACHED_VALUE_USED, $sheet, $cell);
    }

    public function warnValueRounded(string $sheet, string $cell): void
    {
        $this->warnings[] = new Warning(Warning::VALUE_ROUNDED, $sheet, $cell);
    }

    /**
     * @return list<Issue>
     */
    public function issues(): array
    {
        return $this->issues;
    }

    /**
     * @return list<Warning>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
