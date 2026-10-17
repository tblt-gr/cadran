<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Application;

use App\Module\Import\Application\MappingSkeleton;
use App\Module\Import\Domain\Block;
use App\Module\Import\Domain\LabelKey;
use App\Module\Import\Domain\Workbook\ParsedWorkbook;
use App\Module\Import\Domain\Workbook\WorkbookLayoutReader;
use App\Module\Import\Infrastructure\Mapping\YamlMappingReader;
use App\Module\Import\Infrastructure\Xlsx\XlsxPackage;
use App\Tests\Module\Import\Support\WorkbookBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MappingSkeletonTest extends TestCase
{
    /** @var list<WorkbookBuilder> */
    private array $builders = [];

    protected function tearDown(): void
    {
        foreach ($this->builders as $builder) {
            $builder->cleanUp();
        }
    }

    /**
     * @param callable(WorkbookBuilder): void $fill
     */
    private function parse(int $year, callable $fill): ParsedWorkbook
    {
        $builder = new WorkbookBuilder();
        $this->builders[] = $builder;
        $fill($builder);

        return (new WorkbookLayoutReader())->read(XlsxPackage::open($builder->save($year.'.xlsx')), $year, 2);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function document(string $skeleton): array
    {
        $raw = Yaml::parse($skeleton);
        self::assertIsArray($raw);

        return $raw;
    }

    /**
     * @param array<array-key, mixed> $parent
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $parent, string $key): array
    {
        self::assertIsArray($parent[$key]);

        return $parent[$key];
    }

    /**
     * @return list<ParsedWorkbook>
     */
    private function twoYears(): array
    {
        $first = $this->parse(2023, static function (WorkbookBuilder $b): void {
            $b->monthSheet('Novembre')->text('A2', 'Salary')->number('B2', '2000')->text('E2', 'Food')->number('F2', '300')->text('G2', 'Savings A')->number('H2', '100')->text('J2', 'Savings A')->number('L2', '100');
            $b->monthSheet('Décembre')->text('A2', 'Salary')->number('B2', '2000')->text('C2', 'Rent')->number('D2', '700');
            $b->sheet('CTO_TR')->text('D2', 'World ETF')->text('A4', 'Janvier')->text('A15', 'Décembre')->number('D15', '100');
        });
        $second = $this->parse(2024, static function (WorkbookBuilder $b): void {
            $b->monthSheet('Janvier')->text('A2', 'salary ')->number('B2', '2100')->text('E2', 'Food')->number('F2', '310');
            $b->monthSheet('Février')->text('E2', 'Food')->number('F2', '320')->text('E3', 'Gifts')->number('F3', '50');
            $b->monthSheet('Avril')->text('E2', 'Food')->number('F2', '330');
            $b->sheet('PEA')->text('D2', 'MSCI World');
            $b->sheet('CTO_TR')->text('D2', 'World ETF');
        });

        return [$second, $first];
    }

    public function testEveryLabelAppearsOnceWithItsYearsAndMonthsAndNoValue(): void
    {
        $skeleton = (new MappingSkeleton())->render($this->twoYears());

        self::assertStringContainsString("version: 1\n", $skeleton);
        self::assertSame(1, substr_count($skeleton, '"Salary"'), 'two spellings of one label are one entry');
        self::assertStringContainsString('"Salary": null # 2023: 11-12; 2024: 1', $skeleton);
        self::assertStringContainsString('"Food": null # 2023: 11; 2024: 1-2, 4', $skeleton);
        self::assertStringContainsString('"Gifts": null # 2024: 2', $skeleton);
        self::assertStringContainsString('"Rent": null # 2023: 12', $skeleton);
        self::assertStringContainsString('"Savings A": null # 2023: 11', $skeleton);
        self::assertStringContainsString('"World ETF": null # 2023: 12; 2024', $skeleton);
        self::assertStringContainsString('"MSCI World": null # 2024', $skeleton);
    }

    public function testNoAmountEverReachesTheSkeleton(): void
    {
        $skeleton = (new MappingSkeleton())->render($this->twoYears());

        foreach (['2000', '2100', '700', '310', '320', '330', '300'] as $amount) {
            self::assertStringNotContainsString($amount, $skeleton);
        }
    }

    public function testTheSkeletonIsAValidMappingWhoseEveryValueIsNull(): void
    {
        $skeleton = (new MappingSkeleton())->render($this->twoYears());

        $mapping = (new YamlMappingReader())->fromYaml($skeleton);

        self::assertNull($mapping->mainAccount);
        self::assertTrue($mapping->mentions(Block::INC, 'salary'));
        self::assertTrue($mapping->mentions(Block::FIX, 'rent'));
        self::assertTrue($mapping->mentions(Block::VAR, 'gifts'));
        self::assertNull($mapping->lineFor(Block::VAR, 'food'));
        self::assertNull($mapping->savingsTarget('savings-a'));
        self::assertNull($mapping->accountFor('savings-a'));
        $cto = $mapping->portfolio(Block::CTO);
        $pea = $mapping->portfolio(Block::PEA);
        self::assertNotNull($cto);
        self::assertNotNull($pea);
        self::assertTrue($cto->mentionsInstrument('world-etf'));
        self::assertTrue($pea->mentionsInstrument('msci-world'));
        self::assertNull($pea->instrument('msci-world'));

        $raw = self::document($skeleton);
        self::assertNull($raw['main_account']);
        self::assertSame([], $raw['ignore']);
        self::assertSame([], $raw['overrides']);
        foreach (['accounts', 'income', 'fixed', 'variable', 'savings'] as $section) {
            $entries = self::section($raw, $section);
            self::assertNotSame([], $entries, $section);
            self::assertSame([], array_filter($entries, static fn (mixed $value): bool => null !== $value), $section);
        }
        self::assertNull(self::section(self::section($raw, 'portfolio'), 'pea')['account']);
    }

    public function testAwkwardLabelsSurviveTheRoundTripAndNeverBecomeAComment(): void
    {
        $awkward = "Say \"hi\" # not: a comment \\ back\nsecond line: x";
        $workbook = $this->parse(2025, static function (WorkbookBuilder $b) use ($awkward): void {
            $b->monthSheet('Mars')->text('A2', $awkward)->number('B2', '5')->text('A3', 'Ünïcode ☃')->number('B3', '5');
        });

        $skeleton = (new MappingSkeleton())->render([$workbook]);
        $mapping = (new YamlMappingReader())->fromYaml($skeleton);

        self::assertTrue($mapping->mentions(Block::INC, LabelKey::normalize($awkward)));
        self::assertTrue($mapping->mentions(Block::INC, 'unicode'));
        self::assertContains($awkward, array_map('strval', array_keys(self::section(self::document($skeleton), 'income'))));
    }

    public function testAnEmptyWorkbookListStillGivesAValidSkeleton(): void
    {
        $mapping = (new YamlMappingReader())->fromYaml((new MappingSkeleton())->render([]));

        self::assertNull($mapping->mainAccount);
    }
}
