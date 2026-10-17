<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Infrastructure;

use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\Mapping\InstrumentKind;
use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\Mapping\MappingProblemCode;
use App\Module\Import\Domain\WorkbookUsageError;
use App\Module\Import\Infrastructure\Mapping\YamlMappingReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class YamlMappingReaderTest extends TestCase
{
    private const string FULL = <<<'YAML'
        version: 1
        main_account: "Main current account"
        accounts:
          "Savings A": "Savings A"
          "CTO": "Broker account"
          "CTO TR": "Broker account"
        income:
          "Salary": { category: "Income/Salary" }
          "Meal vouchers": { category: "Income/Meal vouchers", account: "Meal vouchers" }
        fixed:
          "Rent": { category: "Housing/Rent" }
        variable:
          "Food": { category: "Food/Groceries" }
        savings:
          "PEA": "PEA"
        portfolio:
          pea:
            account: "PEA"
            instruments:
              "MSCI World": { name: "MSCI World ETF", type: ETF, isin: "IE00B4L5Y983", ticker: "IWDA" }
          cto_tr:
            account: "Broker account"
            instruments:
              "Tech ETF": { name: "Tech ETF", type: FUND }
        ignore:
          "Old line": "Duplicate of another line"
        overrides:
          "2025-05": { pea_quantity_first: [true, false] }
          "2025-08": { cached_cells: ["B2", "D3"] }
          "2024-08": { skip_cells: { "H4": "Duplicate of another line" } }
          "2026-01": { cached_cells: ["H4"], skip_cells: { "B5": "Already netted elsewhere" } }
        YAML;

    public function testAFullMappingIsReadAndLabelsAreComparedAfterNormalization(): void
    {
        $mapping = (new YamlMappingReader())->fromYaml(self::FULL);

        self::assertSame('Main current account', $mapping->mainAccount);
        self::assertSame('Broker account', $mapping->accountFor('cto-tr'));
        self::assertSame('Savings A', $mapping->accountFor('savings-a'));
        self::assertSame('Income/Salary', $mapping->lineFor(Block::INC, 'salary')?->category);
        self::assertNull($mapping->lineFor(Block::INC, 'salary')->account);
        self::assertSame('Meal vouchers', $mapping->lineFor(Block::INC, 'meal-vouchers')?->account);
        self::assertSame('Housing/Rent', $mapping->lineFor(Block::FIX, 'rent')?->category);
        self::assertSame('Food/Groceries', $mapping->lineFor(Block::VAR, 'food')?->category);
        self::assertNull($mapping->lineFor(Block::VAR, 'rent'), 'sections are independent');
        self::assertSame('PEA', $mapping->savingsTarget('pea'));
        self::assertTrue($mapping->isIgnored('old-line'));
        self::assertFalse($mapping->isIgnored('rent'));
        self::assertSame('Duplicate of another line', $mapping->ignoreReason('old-line'));
        self::assertSame([true, false], $mapping->peaQuantityFirst(2025, 5));
        self::assertNull($mapping->peaQuantityFirst(2025, 6));

        $overrides = $mapping->cellOverrides();
        self::assertSame(['B2', 'D3'], $overrides->of(2025, 8)->cached);
        self::assertSame([], $overrides->of(2025, 8)->skipped);
        self::assertSame(['H4' => 'Duplicate of another line'], $overrides->of(2024, 8)->skipped);
        self::assertSame(['H4'], $overrides->of(2026, 1)->cached);
        self::assertSame(['B5' => 'Already netted elsewhere'], $overrides->of(2026, 1)->skipped);
        self::assertSame([], $overrides->of(2025, 9)->cached);
        self::assertSame([8], $overrides->monthsOf(2025));
        self::assertSame([1], $overrides->monthsOf(2026));

        $pea = $mapping->portfolio(Block::PEA);
        self::assertNotNull($pea);
        self::assertSame('PEA', $pea->account);
        $instrument = $pea->instrument('msci-world');
        self::assertNotNull($instrument);
        self::assertSame('MSCI World ETF', $instrument->name);
        self::assertSame(InstrumentKind::ETF, $instrument->type);
        self::assertSame('IE00B4L5Y983', $instrument->isin);
        self::assertSame('IWDA', $instrument->ticker);
        $cto = $mapping->portfolio(Block::CTO);
        self::assertNotNull($cto);
        self::assertSame(InstrumentKind::FUND, $cto->instrument('tech-etf')?->type);
        self::assertNull($cto->instrument('unknown'));
    }

    public function testNullValuesAreAnIncompleteMappingNotAnError(): void
    {
        $mapping = (new YamlMappingReader())->fromYaml(<<<'YAML'
            version: 1
            main_account: null
            accounts:
              "Savings A": null
            income:
              "Salary": null
              "Bonus": { category: null }
            savings:
              "PEA": null
            portfolio:
              pea:
                account: null
                instruments:
                  "MSCI World": null
            ignore: {}
            overrides: {}
            YAML);

        self::assertNull($mapping->mainAccount);
        self::assertNull($mapping->accountFor('savings-a'));
        self::assertNull($mapping->lineFor(Block::INC, 'salary'));
        self::assertNull($mapping->lineFor(Block::INC, 'bonus')?->category);
        self::assertNull($mapping->savingsTarget('pea'));
        self::assertNull($mapping->portfolio(Block::PEA)?->account);
        self::assertNull($mapping->portfolio(Block::PEA)?->instrument('msci-world'));
        self::assertTrue($mapping->mentions(Block::INC, 'salary'));
        self::assertFalse($mapping->mentions(Block::INC, 'absent'));
    }

    public function testAnIncomeLineMayBeATransferFromAnAccount(): void
    {
        $mapping = (new YamlMappingReader())->fromYaml(<<<'YAML'
            version: 1
            main_account: "Main current account"
            income:
              "Withdrawal Savings": { transfer_from: "Savings A" }
              "Other": { transfer_from: "Savings A", account: "Meal vouchers" }
            YAML);

        $default = $mapping->lineFor(Block::INC, 'withdrawal-savings');
        self::assertSame('Savings A', $default?->transferFrom);
        self::assertNull($default->category);
        self::assertNull($default->account);
        $explicit = $mapping->lineFor(Block::INC, 'other');
        self::assertSame('Savings A', $explicit?->transferFrom);
        self::assertSame('Meal vouchers', $explicit->account);
    }

    public function testOnlyTheVersionIsRequired(): void
    {
        $mapping = (new YamlMappingReader())->fromYaml('version: 1');

        self::assertNull($mapping->mainAccount);
        self::assertNull($mapping->lineFor(Block::INC, 'salary'));
        self::assertNull($mapping->portfolio(Block::PEA));
    }

    /**
     * @param array<string, MappingProblemCode> $expected location => code
     */
    #[DataProvider('invalidMappings')]
    public function testAnInvalidMappingReportsEveryProblemByLocationWithoutQuotingLabels(string $yaml, array $expected): void
    {
        try {
            (new YamlMappingReader())->fromYaml($yaml);
            self::fail('The mapping must be refused.');
        } catch (InvalidMapping $invalid) {
            $found = [];
            foreach ($invalid->problems as $problem) {
                $found[$problem->location] = $problem->code;
            }
            self::assertSame($expected, $found);
            self::assertStringNotContainsString('Secret', $invalid->getMessage());
            foreach ($invalid->problems as $problem) {
                self::assertStringNotContainsString('Secret', $problem->location);
            }
        }
    }

    /**
     * @return iterable<string, array{string, array<string, MappingProblemCode>}>
     */
    public static function invalidMappings(): iterable
    {
        yield 'missing version' => ["main_account: x\n", ['version' => MappingProblemCode::UNSUPPORTED_VERSION]];
        yield 'unsupported version' => ["version: 2\n", ['version' => MappingProblemCode::UNSUPPORTED_VERSION]];
        yield 'unknown top-level key' => ["version: 1\nSecret: 1\n", ['(root)' => MappingProblemCode::UNKNOWN_KEY]];
        yield 'a section that is a list' => ["version: 1\nincome:\n  - Secret\n", ['income' => MappingProblemCode::NOT_A_MAP]];
        yield 'an entry that is a bare string' => ["version: 1\nincome:\n  Secret: Income/Salary\n", ['income[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'an income entry with both a category and a transfer source' => [
            "version: 1\nincome:\n  Secret: { category: A/B, transfer_from: Savings A }\n",
            ['income[1]' => MappingProblemCode::INVALID_VALUE],
        ];
        yield 'an income entry with neither a category nor a transfer source' => [
            "version: 1\nincome:\n  Secret: { account: Main }\n  Other: {}\n",
            ['income[1]' => MappingProblemCode::INVALID_VALUE, 'income[2]' => MappingProblemCode::INVALID_VALUE],
        ];
        yield 'a transfer from the explicit destination account' => [
            "version: 1\nincome:\n  Secret: { transfer_from: Savings A, account: Savings A }\n",
            ['income[1].account' => MappingProblemCode::INVALID_VALUE],
        ];
        yield 'a transfer from the main account to the default destination' => [
            "version: 1\nmain_account: Main\nincome:\n  Secret: { transfer_from: Main }\n",
            ['income[1].transfer_from' => MappingProblemCode::INVALID_VALUE],
        ];
        yield 'a transfer source that is not a name' => [
            "version: 1\nincome:\n  Secret: { transfer_from: 5 }\n",
            ['income[1].transfer_from' => MappingProblemCode::INVALID_VALUE],
        ];
        yield 'a transfer source outside the income section' => [
            "version: 1\nfixed:\n  Secret: { transfer_from: Savings A }\n",
            ['fixed[1]' => MappingProblemCode::UNKNOWN_KEY],
        ];
        yield 'unknown key inside an entry' => ["version: 1\nfixed:\n  Secret: { category: A/B, colour: red }\n", ['fixed[1]' => MappingProblemCode::UNKNOWN_KEY]];
        yield 'a category that is not a string' => ["version: 1\nvariable:\n  Secret: { category: 5 }\n", ['variable[1].category' => MappingProblemCode::INVALID_VALUE]];
        yield 'malformed category paths' => [
            "version: 1\nvariable:\n  Secret: { category: 'A//B' }\n  Other: { category: '/A' }\n  Third: { category: 'A/' }\n",
            ['variable[1].category' => MappingProblemCode::INVALID_CATEGORY_PATH, 'variable[2].category' => MappingProblemCode::INVALID_CATEGORY_PATH, 'variable[3].category' => MappingProblemCode::INVALID_CATEGORY_PATH],
        ];
        yield 'two spellings of the same label' => ["version: 1\nincome:\n  Dépenses: null\n  depenses: null\n", ['income[2]' => MappingProblemCode::DUPLICATE_LABEL]];
        yield 'a label that normalizes to nothing' => ["version: 1\naccounts:\n  '---': x\n", ['accounts[1]' => MappingProblemCode::EMPTY_LABEL]];
        yield 'an empty account label' => ["version: 1\nmain_account: ''\n", ['main_account' => MappingProblemCode::INVALID_VALUE]];
        yield 'an unknown instrument type' => [
            "version: 1\nportfolio:\n  pea:\n    account: A\n    instruments:\n      Secret: { name: X, type: SHARE }\n",
            ['portfolio.pea.instruments[1].type' => MappingProblemCode::INVALID_INSTRUMENT],
        ];
        yield 'an instrument without a name' => [
            "version: 1\nportfolio:\n  cto_tr:\n    instruments:\n      Secret: { type: ETF }\n",
            ['portfolio.cto_tr.instruments[1].name' => MappingProblemCode::INVALID_INSTRUMENT],
        ];
        yield 'a malformed isin' => [
            "version: 1\nportfolio:\n  pea:\n    instruments:\n      Secret: { name: X, type: ETF, isin: nope }\n",
            ['portfolio.pea.instruments[1].isin' => MappingProblemCode::INVALID_INSTRUMENT],
        ];
        yield 'an unknown portfolio sheet' => ["version: 1\nportfolio:\n  isa: {}\n", ['portfolio' => MappingProblemCode::UNKNOWN_KEY]];
        yield 'an ignore entry without a reason' => ["version: 1\nignore:\n  Secret: ''\n", ['ignore[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'a malformed override month' => ["version: 1\noverrides:\n  May-2025: { pea_quantity_first: [true] }\n", ['overrides[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'an override that is not a list of booleans' => ["version: 1\noverrides:\n  '2025-05': { pea_quantity_first: [1, true] }\n", ['overrides[1].pea_quantity_first' => MappingProblemCode::INVALID_VALUE]];
        yield 'a cached cell outside the amount columns' => ["version: 1\noverrides:\n  '2025-05': { cached_cells: [A2, B3, C4] }\n", ['overrides[1].cached_cells[1]' => MappingProblemCode::INVALID_VALUE, 'overrides[1].cached_cells[3]' => MappingProblemCode::INVALID_VALUE]];
        yield 'a cached cell on the header row' => ["version: 1\noverrides:\n  '2025-05': { cached_cells: [B1] }\n", ['overrides[1].cached_cells[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'cached cells that are not a list of coordinates' => ["version: 1\noverrides:\n  '2025-05': { cached_cells: B2 }\n", ['overrides[1].cached_cells' => MappingProblemCode::INVALID_VALUE]];
        yield 'a skipped cell outside the amount columns' => ["version: 1\noverrides:\n  '2025-05': { skip_cells: { E2: why } }\n", ['overrides[1].skip_cells[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'a skipped cell without a reason' => ["version: 1\noverrides:\n  '2025-05': { skip_cells: { B2: '', D2: ok } }\n", ['overrides[1].skip_cells[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'a skipped cell with a reason over 200 characters' => ["version: 1\noverrides:\n  '2025-05': { skip_cells: { B2: ".str_repeat('x', 201)." } }\n", ['overrides[1].skip_cells[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'skip cells that are not a map' => ["version: 1\noverrides:\n  '2025-05': { skip_cells: [B2] }\n", ['overrides[1].skip_cells' => MappingProblemCode::NOT_A_MAP]];
        yield 'a cell both cached and skipped' => ["version: 1\noverrides:\n  '2025-05': { cached_cells: [B2], skip_cells: { B2: why } }\n", ['overrides[1]' => MappingProblemCode::INVALID_VALUE]];
        yield 'several problems are all reported' => [
            "version: 2\nincome:\n  Secret: Income/Salary\n",
            ['version' => MappingProblemCode::UNSUPPORTED_VERSION, 'income[1]' => MappingProblemCode::INVALID_VALUE],
        ];
    }

    public function testAYamlSyntaxErrorIsReportedByLineNeverByContent(): void
    {
        try {
            (new YamlMappingReader())->fromYaml("version: 1\nincome:\n  Secret: [unclosed\n");
            self::fail('The mapping must be refused.');
        } catch (InvalidMapping $invalid) {
            self::assertSame(MappingProblemCode::YAML_SYNTAX, $invalid->problems[0]->code);
            self::assertStringStartsWith('line ', $invalid->problems[0]->location);
            self::assertStringNotContainsString('Secret', $invalid->getMessage());
        }
    }

    public function testADuplicateYamlKeyIsRefusedInsteadOfKeepingOneSilently(): void
    {
        try {
            (new YamlMappingReader())->fromYaml("version: 1\nincome:\n  Secret: null\n  Secret: null\n");
            self::fail('The mapping must be refused.');
        } catch (InvalidMapping $invalid) {
            self::assertSame(MappingProblemCode::DUPLICATE_KEY, $invalid->problems[0]->code);
            self::assertStringNotContainsString('Secret', $invalid->getMessage());
        }
    }

    public function testYamlTagsAndObjectsAreNotInterpreted(): void
    {
        $this->expectException(InvalidMapping::class);

        (new YamlMappingReader())->fromYaml("version: 1\nmain_account: !php/object 'O:8:\"stdClass\":0:{}'\n");
    }

    public function testTooManyEntriesInASectionAreRefused(): void
    {
        $yaml = "version: 1\nincome:\n";
        for ($i = 0; $i < 501; ++$i) {
            $yaml .= sprintf("  label%d: null\n", $i);
        }

        try {
            (new YamlMappingReader())->fromYaml($yaml);
            self::fail('The mapping must be refused.');
        } catch (InvalidMapping $invalid) {
            self::assertSame(MappingProblemCode::TOO_MANY_ENTRIES, $invalid->problems[0]->code);
        }
    }

    public function testAMappingFileIsReadFromDisk(): void
    {
        $path = sys_get_temp_dir().'/'.uniqid('mapping-', true).'.yaml';
        file_put_contents($path, "version: 1\nmain_account: Main\n");

        try {
            self::assertSame('Main', (new YamlMappingReader())->read($path)->mainAccount);
        } finally {
            unlink($path);
        }
    }

    public function testAMissingOrOversizedMappingFileIsAUsageError(): void
    {
        $reader = new YamlMappingReader();
        try {
            $reader->read('/nonexistent/mapping.yaml');
            self::fail('A missing file is a usage error.');
        } catch (WorkbookUsageError) {
        }

        $path = sys_get_temp_dir().'/'.uniqid('mapping-', true).'.yaml';
        file_put_contents($path, "version: 1\n# ".str_repeat('x', 1024 * 1024)."\n");
        try {
            $this->expectException(WorkbookUsageError::class);
            $reader->read($path);
        } finally {
            unlink($path);
        }
    }
}
