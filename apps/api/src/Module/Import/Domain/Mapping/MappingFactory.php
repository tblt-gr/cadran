<?php

declare(strict_types=1);

namespace App\Module\Import\Domain\Mapping;

use App\Module\Import\Domain\LabelKey;

/**
 * Validates the decoded mapping document entirely and builds the
 * {@see ImportMapping}. Every problem is collected, located by section and
 * position, and none quotes a label.
 */
final class MappingFactory
{
    private const int MAX_ENTRIES = 500;
    private const int MAX_NAME_LENGTH = 255;
    private const int MAX_REASON_LENGTH = 200;
    private const array ROOT_KEYS = ['version', 'main_account', 'accounts', 'income', 'fixed', 'variable', 'savings', 'portfolio', 'ignore', 'overrides'];

    /** @var list<MappingProblem> */
    private array $problems = [];

    private ?string $mainAccountLabel = null;

    /** @var array<string, MonthCellOverrides> */
    private array $cellOverrides = [];

    /**
     * @throws InvalidMapping
     */
    public static function fromDocument(mixed $document): ImportMapping
    {
        $factory = new self();
        $mapping = $factory->build($document);

        if ([] !== $factory->problems) {
            throw new InvalidMapping($factory->problems);
        }

        return $mapping;
    }

    private function build(mixed $document): ImportMapping
    {
        $root = $this->map($document, '(root)', self::ROOT_KEYS);

        if (1 !== ($root['version'] ?? null)) {
            $this->problem('version', MappingProblemCode::UNSUPPORTED_VERSION);
        }

        $portfolio = $this->map($root['portfolio'] ?? null, 'portfolio', ['pea', 'cto_tr']);

        $this->mainAccountLabel = $this->optionalName($root['main_account'] ?? null, 'main_account');

        return new ImportMapping(
            $this->mainAccountLabel,
            $this->nameSection($root['accounts'] ?? null, 'accounts'),
            $this->lineSection($root['income'] ?? null, 'income'),
            $this->lineSection($root['fixed'] ?? null, 'fixed'),
            $this->lineSection($root['variable'] ?? null, 'variable'),
            $this->nameSection($root['savings'] ?? null, 'savings'),
            $this->portfolioSheet($portfolio['pea'] ?? null, 'portfolio.pea'),
            $this->portfolioSheet($portfolio['cto_tr'] ?? null, 'portfolio.cto_tr'),
            $this->ignoreSection($root['ignore'] ?? null),
            $this->overrides($root['overrides'] ?? null),
            new CellOverrides($this->cellOverrides),
        );
    }

    /**
     * @return array<string, ?string>
     */
    private function nameSection(mixed $section, string $location): array
    {
        $result = [];
        foreach ($this->labelled($section, $location) as $position => [$key, $value]) {
            $result[$key] = $this->optionalName($value, sprintf('%s[%d]', $location, $position));
        }

        return $result;
    }

    /**
     * @return array<string, ?LineMapping>
     */
    private function lineSection(mixed $section, string $location): array
    {
        $result = [];
        foreach ($this->labelled($section, $location) as $position => [$key, $value]) {
            $entry = sprintf('%s[%d]', $location, $position);
            if (null === $value) {
                $result[$key] = null;
                continue;
            }
            if (!$this->isMap($value)) {
                $this->problem($entry, MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $isIncome = 'income' === $location;
            $fields = $this->map($value, $entry, $isIncome ? ['category', 'account', 'transfer_from'] : ['category', 'account']);

            $category = $this->category($fields['category'] ?? null, $entry.'.category');
            $account = $this->optionalName($fields['account'] ?? null, $entry.'.account');
            $transferFrom = $isIncome ? $this->optionalName($fields['transfer_from'] ?? null, $entry.'.transfer_from') : null;
            if ($isIncome) {
                $this->checkTransfer($fields, $entry, $category, $account, $transferFrom);
            }

            $result[$key] = new LineMapping($category, $account, $transferFrom);
        }

        return $result;
    }

    /**
     * An income entry is a category or a transfer source, never both; an entry that names neither key is
     * refused, while an explicit `category: null` stays a label not mapped yet.
     *
     * @param array<string, mixed> $fields
     */
    private function checkTransfer(array $fields, string $entry, ?string $category, ?string $account, ?string $transferFrom): void
    {
        if (null !== $category && null !== $transferFrom) {
            $this->problem($entry, MappingProblemCode::INVALID_VALUE);

            return;
        }
        if (!array_key_exists('category', $fields) && !array_key_exists('transfer_from', $fields)) {
            $this->problem($entry, MappingProblemCode::INVALID_VALUE);

            return;
        }
        if (null === $transferFrom) {
            return;
        }
        if (null !== $account && $account === $transferFrom) {
            $this->problem($entry.'.account', MappingProblemCode::INVALID_VALUE);
        } elseif (null === $account && null !== $this->mainAccountLabel && $this->mainAccountLabel === $transferFrom) {
            $this->problem($entry.'.transfer_from', MappingProblemCode::INVALID_VALUE);
        }
    }

    /**
     * @return array<string, string>
     */
    private function ignoreSection(mixed $section): array
    {
        $result = [];
        foreach ($this->labelled($section, 'ignore') as $position => [$key, $value]) {
            if (!is_string($value) || '' === trim($value) || strlen($value) > self::MAX_NAME_LENGTH) {
                $this->problem(sprintf('ignore[%d]', $position), MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private function portfolioSheet(mixed $sheet, string $location): ?PortfolioMapping
    {
        if (null === $sheet) {
            return null;
        }
        if (!$this->isMap($sheet)) {
            $this->problem($location, MappingProblemCode::NOT_A_MAP);

            return null;
        }
        $fields = $this->map($sheet, $location, ['account', 'instruments']);

        $instruments = [];
        foreach ($this->labelled($fields['instruments'] ?? null, $location.'.instruments') as $position => [$key, $value]) {
            $instruments[$key] = null === $value ? null : $this->instrument($value, sprintf('%s.instruments[%d]', $location, $position));
        }

        return new PortfolioMapping($this->optionalName($fields['account'] ?? null, $location.'.account'), $instruments);
    }

    private function instrument(mixed $value, string $location): ?InstrumentMapping
    {
        if (!$this->isMap($value)) {
            $this->problem($location, MappingProblemCode::INVALID_INSTRUMENT);

            return null;
        }
        $fields = $this->map($value, $location, ['name', 'type', 'isin', 'ticker']);

        $name = $fields['name'] ?? null;
        if (!is_string($name) || '' === trim($name) || strlen($name) > self::MAX_NAME_LENGTH) {
            $this->problem($location.'.name', MappingProblemCode::INVALID_INSTRUMENT);
            $name = null;
        }

        $type = is_string($fields['type'] ?? null) ? InstrumentKind::tryFrom($fields['type']) : null;
        if (null === $type) {
            $this->problem($location.'.type', MappingProblemCode::INVALID_INSTRUMENT);
        }

        $isin = $fields['isin'] ?? null;
        $validIsin = $this->isNullOrMatching($isin, '/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D', $location.'.isin');
        $ticker = $fields['ticker'] ?? null;
        $validTicker = $this->isNullOrMatching($ticker, '/^[A-Za-z0-9.\-]{1,32}$/D', $location.'.ticker');

        if (null === $name || null === $type || !$validIsin || !$validTicker) {
            return null;
        }

        return new InstrumentMapping($name, $type, is_string($isin) ? $isin : null, is_string($ticker) ? $ticker : null);
    }

    private function isNullOrMatching(mixed $value, string $pattern, string $location): bool
    {
        if (null === $value || (is_string($value) && 1 === preg_match($pattern, $value))) {
            return true;
        }
        $this->problem($location, MappingProblemCode::INVALID_INSTRUMENT);

        return false;
    }

    /**
     * @return array<string, list<bool>>
     */
    private function overrides(mixed $section): array
    {
        $result = [];
        foreach ($this->pairs($section, 'overrides') as $position => [$key, $value]) {
            $location = sprintf('overrides[%d]', $position);
            if (1 !== preg_match('/^[0-9]{4}-(?:0[1-9]|1[0-2])$/D', $key) || !$this->isMap($value)) {
                $this->problem($location, MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $fields = $this->map($value, $location, ['pea_quantity_first', 'cached_cells', 'skip_cells']);
            $this->monthCells($key, $fields, $location);

            $flags = $fields['pea_quantity_first'] ?? null;
            if (null === $flags) {
                continue;
            }
            $list = $this->booleanList($flags);
            if (null === $list) {
                $this->problem($location.'.pea_quantity_first', MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $result[$key] = $list;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function monthCells(string $month, array $fields, string $location): void
    {
        $cached = $this->cachedCells($fields['cached_cells'] ?? null, $location.'.cached_cells');
        $skipped = $this->skippedCells($fields['skip_cells'] ?? null, $location.'.skip_cells');
        if ([] !== array_intersect($cached, array_keys($skipped))) {
            $this->problem($location, MappingProblemCode::INVALID_VALUE);

            return;
        }
        if ([] !== $cached || [] !== $skipped) {
            $this->cellOverrides[$month] = new MonthCellOverrides($cached, $skipped);
        }
    }

    /**
     * @return list<string>
     */
    private function cachedCells(mixed $value, string $location): array
    {
        if (null === $value) {
            return [];
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_ENTRIES) {
            $this->problem($location, MappingProblemCode::INVALID_VALUE);

            return [];
        }

        $cells = [];
        foreach ($value as $index => $cell) {
            if (!is_string($cell) || !self::isAmountCell($cell)) {
                $this->problem(sprintf('%s[%d]', $location, $index + 1), MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $cells[$cell] = $cell;
        }

        return array_values($cells);
    }

    /**
     * @return array<string, string>
     */
    private function skippedCells(mixed $value, string $location): array
    {
        $cells = [];
        foreach ($this->pairs($value, $location) as $position => [$cell, $reason]) {
            if (!self::isAmountCell($cell) || !is_string($reason) || '' === trim($reason) || strlen($reason) > self::MAX_REASON_LENGTH) {
                $this->problem(sprintf('%s[%d]', $location, $position), MappingProblemCode::INVALID_VALUE);
                continue;
            }
            $cells[$cell] = $reason;
        }

        return $cells;
    }

    /** A movement amount cell of a month sheet: column B, D, F or H, below the header row. */
    private static function isAmountCell(string $cell): bool
    {
        return 1 === preg_match('/^[BDFH]([1-9][0-9]{0,3})$/D', $cell, $matches) && (int) $matches[1] >= 2;
    }

    /**
     * @return list<bool>|null
     */
    private function booleanList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 64) {
            return null;
        }

        $list = [];
        foreach ($value as $flag) {
            if (!is_bool($flag)) {
                return null;
            }
            $list[] = $flag;
        }

        return $list;
    }

    /**
     * The entries of a label-keyed section by normalized label, numbered from 1
     * in file order. An unusable or repeated label is reported and skipped.
     *
     * @return array<int, array{string, mixed}> position => [label key, value]
     */
    private function labelled(mixed $section, string $location): array
    {
        $result = [];
        $seen = [];
        foreach ($this->pairs($section, $location) as $position => [$label, $value]) {
            $key = LabelKey::normalize($label);
            if ('' === $key) {
                $this->problem(sprintf('%s[%d]', $location, $position), MappingProblemCode::EMPTY_LABEL);
                continue;
            }
            if (isset($seen[$key])) {
                $this->problem(sprintf('%s[%d]', $location, $position), MappingProblemCode::DUPLICATE_LABEL);
                continue;
            }
            $seen[$key] = true;
            $result[$position] = [$key, $value];
        }

        return $result;
    }

    /**
     * A section as numbered [key, value] pairs, empty when it is absent or unusable.
     *
     * @return array<int, array{string, mixed}>
     */
    private function pairs(mixed $section, string $location): array
    {
        if (null === $section) {
            return [];
        }
        if (!$this->isMap($section)) {
            $this->problem($location, MappingProblemCode::NOT_A_MAP);

            return [];
        }
        if (count($section) > self::MAX_ENTRIES) {
            $this->problem($location, MappingProblemCode::TOO_MANY_ENTRIES);

            return [];
        }

        $pairs = [];
        $position = 0;
        foreach ($section as $key => $value) {
            $pairs[++$position] = [(string) $key, $value];
        }

        return $pairs;
    }

    /**
     * A mapping of known keys: unknown ones are reported, and an unusable
     * value gives an empty map.
     *
     * @param list<string> $allowed
     *
     * @return array<string, mixed>
     */
    private function map(mixed $value, string $location, array $allowed): array
    {
        if (null === $value) {
            return [];
        }
        if (!$this->isMap($value)) {
            $this->problem($location, MappingProblemCode::NOT_A_MAP);

            return [];
        }

        $map = [];
        foreach ($value as $key => $entry) {
            if (!in_array((string) $key, $allowed, true)) {
                $this->problem($location, MappingProblemCode::UNKNOWN_KEY);
                continue;
            }
            $map[(string) $key] = $entry;
        }

        return $map;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private function isMap(mixed $value): bool
    {
        return is_array($value) && ([] === $value || !array_is_list($value));
    }

    private function optionalName(mixed $value, string $location): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === trim($value) || strlen($value) > self::MAX_NAME_LENGTH) {
            $this->problem($location, MappingProblemCode::INVALID_VALUE);

            return null;
        }

        return $value;
    }

    private function category(mixed $value, string $location): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || strlen($value) > self::MAX_NAME_LENGTH) {
            $this->problem($location, MappingProblemCode::INVALID_VALUE);

            return null;
        }
        foreach (explode('/', $value) as $segment) {
            if ('' === trim($segment)) {
                $this->problem($location, MappingProblemCode::INVALID_CATEGORY_PATH);

                return null;
            }
        }

        return $value;
    }

    private function problem(string $location, MappingProblemCode $code): void
    {
        $this->problems[] = new MappingProblem($location, $code);
    }
}
