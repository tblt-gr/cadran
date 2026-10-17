<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Workbook;

use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\LabelKey;
use App\Module\Import\Domain\Mapping\CellOverrides;
use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\Mapping\MappingProblem;
use App\Module\Import\Domain\Mapping\MappingProblemCode;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Sheet\SheetSource;

/**
 * Turns an opened workbook into a {@see ParsedWorkbook}: finds the month sheets,
 * `PEA` and `CTO_TR` by normalized name (sheet order is arbitrary), validates
 * each layout and lists every other sheet as ignored without opening it.
 *
 * Nothing here knows the mapping or the database: this is the pure parsing half
 * of the import, and its output is the planner's input.
 */
final class WorkbookLayoutReader
{
    private const array MONTHS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    public function __construct(
        private readonly MonthSheetReader $monthSheets = new MonthSheetReader(),
        private readonly PortfolioSheetReader $portfolioSheets = new PortfolioSheetReader(),
    ) {
    }

    /** The month number of a normalized month name (`aout` is 8), or null. */
    public static function monthOfName(string $normalizedName): ?int
    {
        return self::MONTHS[$normalizedName] ?? null;
    }

    /**
     * @param int $precision decimals of the asset the amounts are checked against (2 for EUR)
     *
     * @throws InvalidMapping a cell override names a month the workbook has no sheet for
     */
    public function read(SheetSource $source, int $year, int $precision = 2, ?CellOverrides $overrides = null): ParsedWorkbook
    {
        $overrides ??= new CellOverrides();
        $findings = new Findings();

        /** @var array<int, string> $monthSheets */
        $monthSheets = [];
        $peaSheet = null;
        $ctoSheet = null;
        $ignored = [];

        foreach ($source->sheetNames() as $name) {
            $key = LabelKey::normalize($name);
            $month = self::monthOfName($key);

            $duplicate = (null !== $month && isset($monthSheets[$month]))
                || ('pea' === $key && null !== $peaSheet)
                || ('cto-tr' === $key && null !== $ctoSheet);
            if ($duplicate) {
                $findings->add(ReasonCode::UNKNOWN_SHEET_NAME, $name, null);
                continue;
            }

            if (null !== $month) {
                $monthSheets[$month] = $name;
            } elseif ('pea' === $key) {
                $peaSheet = $name;
            } elseif ('cto-tr' === $key) {
                $ctoSheet = $name;
            } else {
                $ignored[] = $name;
            }
        }

        if ([] === $monthSheets) {
            $findings->add(ReasonCode::UNKNOWN_LAYOUT, null, null);
        }

        $missing = array_values(array_filter($overrides->monthsOf($year), static fn (int $month): bool => !isset($monthSheets[$month])));
        if ([] !== $missing) {
            throw new InvalidMapping(array_map(static fn (int $month): MappingProblem => new MappingProblem(sprintf('overrides.%04d-%02d', $year, $month), MappingProblemCode::INVALID_VALUE), $missing));
        }

        ksort($monthSheets);
        $months = [];
        foreach ($monthSheets as $month => $name) {
            $parsed = $this->monthSheets->read($source->readSheet($name), $month, $precision, $findings, $overrides->of($year, $month));
            if (null !== $parsed) {
                $months[] = $parsed;
            }
        }

        $pea = null === $peaSheet ? null : $this->portfolioSheets->read($source->readSheet($peaSheet), Block::PEA, ['D'], 'E', $precision, $findings);
        $cto = null === $ctoSheet ? null : $this->portfolioSheets->read($source->readSheet($ctoSheet), Block::CTO, ['D', 'E', 'F', 'G'], 'H', $precision, $findings);

        return new ParsedWorkbook($year, $months, $pea, $cto, $ignored, $findings->issues(), $findings->warnings());
    }
}
