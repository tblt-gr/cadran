<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

use App\Module\Import\Application\ImportPlanner;
use App\Module\Import\Domain\Plan\ImportPlan;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Plan\PlannedBuy;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedRefund;
use App\Module\Import\Domain\Plan\PlannedStep;
use App\Module\Import\Domain\Plan\PlannedTransaction;
use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Module\Import\Domain\Plan\PlannedValuation;
use App\Module\Import\Infrastructure\Mapping\YamlMappingReader;
use App\Module\Import\Infrastructure\Xlsx\XlsxWorkbookReader;
use PHPUnit\Framework\TestCase;

/** Plans synthetic workbooks against in-memory workspace facts; labels are neutral. */
abstract class PlannerTestCase extends TestCase
{
    protected const string MAPPING = <<<'YAML'
        version: 1
        main_account: "Main current account"
        accounts:
          "Main account": "Main current account"
          "Savings A": "Savings A"
          "Meals": "Meal vouchers"
          "PEA": "PEA account"
          "CTO": "Broker account"
        income:
          "Salary": { category: "Income/Salary" }
          "Meal vouchers": { category: "Income/Meals", account: "Meal vouchers" }
          "Savings withdrawal": { transfer_from: "Savings A" }
          "Voucher top-up": { transfer_from: "Savings A", account: "Meal vouchers" }
          "Ghost transfer": { transfer_from: "Unknown account" }
        fixed:
          "Rent": { category: "Housing/Rent" }
        variable:
          "Food": { category: "Food/Groceries" }
          "Snacks": { category: "Food/Groceries" }
          "Fuel": { category: "Transport/Fuel" }
        savings:
          "Savings A": "Savings A"
          "PEA": "PEA account"
        portfolio:
          pea:
            account: "PEA account"
            instruments:
              "MSCI World": { name: "World ETF", type: ETF, ticker: "WRLD" }
          cto_tr:
            account: "Broker account"
            instruments:
              "Tech ETF": { name: "Tech ETF", type: ETF, ticker: "TECH" }
              "Bond Fund": { name: "Bond Fund", type: FUND, isin: "IE00B4L5Y983" }
        YAML;

    protected FakeImportReferences $references;
    protected WorkbookBuilder $workbook;

    protected function setUp(): void
    {
        $this->workbook = new WorkbookBuilder();
        $this->references = (new FakeImportReferences())
            ->withAccount('Main current account')
            ->withAccount('Savings A')
            ->withAccount('Meal vouchers')
            ->withAccount('PEA account', 'PORTFOLIO')
            ->withAccount('Broker account', 'PORTFOLIO')
            ->withCategory('Income/Salary', 'INCOME')
            ->withCategory('Income/Meals', 'INCOME')
            ->withCategory('Housing/Rent', 'EXPENSE')
            ->withCategory('Food/Groceries', 'EXPENSE')
            ->withCategory('Transport/Fuel', 'EXPENSE', true);
    }

    protected function tearDown(): void
    {
        $this->workbook->cleanUp();
    }

    protected function plan(string $mapping = self::MAPPING, int $year = 2025): ImportPlan
    {
        $parsedMapping = (new YamlMappingReader())->fromYaml($mapping);
        $workbook = (new XlsxWorkbookReader())->read($this->workbook->save($year.'_sample.xlsx'), $year, 2, $parsedMapping->cellOverrides());

        return (new ImportPlanner($this->references))->plan($workbook, $parsedMapping);
    }

    protected static function transactionAt(ImportPlan $plan, int $index): PlannedTransaction
    {
        $step = $plan->steps[$index] ?? null;
        self::assertInstanceOf(PlannedTransaction::class, $step);

        return $step;
    }

    /**
     * @return list<string> `CODE@sheet!cell` of every rejection and exclusion, in order
     */
    protected static function issues(ImportPlan $plan): array
    {
        return array_map(
            static fn ($issue): string => $issue->code->value.'@'.($issue->sheet ?? '-').'!'.($issue->cell ?? '-'),
            $plan->issues,
        );
    }

    /**
     * @return list<string> one compact line per planned step, in execution order
     */
    protected static function describe(ImportPlan $plan): array
    {
        return array_map(static fn (PlannedStep $step): string => self::line($step), $plan->steps);
    }

    private static function line(PlannedStep $step): string
    {
        return match (true) {
            $step instanceof PlannedTransaction => sprintf('%s %s %s %s', $step->kind()->name, $step->bookedOn, $step->amount->toString(), $step->sourceRef->toString()),
            $step instanceof PlannedRefund => sprintf('REFUND %s %s of %s', $step->bookedOn, $step->amount->toString(), $step->originalKey),
            $step instanceof PlannedTransfer => sprintf('TRANSFER %s %s %s>%s', $step->bookedOn, $step->amount->toString(), $step->sourceAccountId, $step->targetAccountId),
            $step instanceof PlannedDeposit => sprintf('DEPOSIT %s %s %s%s', $step->bookedOn, $step->amount->toString(), $step->accountId, null === $step->linkedTransferKey ? ' unlinked' : ' linked'),
            $step instanceof PlannedBuy => sprintf('BUY %s %s x %s = %s %s [%s]', $step->bookedOn, $step->quantity->toString(), $step->unitPrice->toString(), $step->gross->toString(), $step->instrument, $step->reference),
            $step instanceof PlannedBalance => sprintf('BALANCE %s %s %s', $step->asOf, $step->accountId, $step->amount->toString()),
            $step instanceof PlannedValuation => sprintf('VALUATION %s total %s cash %s %s', $step->valuedOn, $step->total->toString(), $step->cash->toString(), $step->accountId),
            default => throw new \LogicException('Unknown step.'),
        };
    }
}
