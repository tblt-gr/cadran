<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * One full synthetic year (twelve month sheets in reverse order, PEA, CTO_TR,
 * ignored sheets) with every layout variation the import supports, in neutral
 * labels. The figures are chosen so the year's totals can be added by hand:
 *
 * - each month: Salary 2 500.00, Meal vouchers top-up 20 x 7.50 = 150.00, Rent
 *   800.00, Food 12.50 + 48.20 + 7.90 = 68.60, Fuel 40.00, Meal voucher
 *   spending 11.50 + 9.80 = 21.30, savings 200.00 to Savings A and 300.00 to PEA;
 * - March: a second Food line 20 + 15 - 5 (two purchases and a refund of 5.00);
 * - June and December: 0.54 of interest credited to Savings A;
 * - the main account opens the first month with 1 000.00.
 */
final class SyntheticYear
{
    public const string MAPPING = <<<'YAML'
        version: 1
        main_account: "Main current account"
        accounts:
          "Main account": "Main current account"
          "Savings A": "Savings A"
          "Meal vouchers": "Meal vouchers"
          "PEA": "PEA account"
          "CTO TR": "Broker account"
        income:
          "Salary": { category: "Income/Salary" }
          "Meal vouchers": { category: "Income/Meals", account: "Meal vouchers" }
          "Savings A interest": { category: "Income/Interest", account: "Savings A" }
        fixed:
          "Rent": { category: "Housing/Rent" }
        variable:
          "Food": { category: "Food/Groceries" }
          "Fuel": { category: "Transport/Fuel" }
          "Meal voucher spending": { category: "Food/Meals", account: "Meal vouchers" }
          "Gym": { category: "Food/Groceries" }
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

    private const array SHEETS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    public static function build(WorkbookBuilder $workbook): void
    {
        // The balances chain forward from January, the sheets are then written most recent month first.
        $main = DecimalValue::fromString('1000.00');
        $savings = DecimalValue::fromString('0.00');
        $meals = DecimalValue::fromString('0.00');
        $balances = [];
        foreach (array_keys(self::SHEETS) as $index) {
            $month = $index + 1;
            $interest = 6 === $month || 12 === $month;
            $balances[$month] = [[$main->toString(), $savings->toString(), $meals->toString()]];
            $main = ExactDecimal::add($main, DecimalValue::fromString('1091.40'));
            $savings = ExactDecimal::add($savings, DecimalValue::fromString($interest ? '200.54' : '200.00'));
            $meals = ExactDecimal::add($meals, DecimalValue::fromString('128.70'));
            if (3 === $month) {
                $main = ExactDecimal::subtract($main, DecimalValue::fromString('30.00'));
            }
            $balances[$month][] = [$main->toString(), $savings->toString(), $meals->toString()];
        }
        foreach (array_reverse(array_keys(self::SHEETS)) as $index) {
            $month = $index + 1;
            [[$mainOpening, $savingsOpening, $mealsOpening], [$mainClosing, $savingsClosing, $mealsClosing]] = $balances[$month];
            self::monthSheet(
                $workbook, $month, 6 === $month || 12 === $month,
                [$mainOpening, $mainClosing], [$savingsOpening, $savingsClosing], [$mealsOpening, $mealsClosing],
            );
        }
        self::portfolioSheets($workbook);
        foreach (['Recap', 'Impôts', 'Paramètres'] as $name) {
            $workbook->sheet($name)->text('A1', 'Derived')->number('B1', '123.45');
        }
    }

    /**
     * @param array{string, string} $main
     * @param array{string, string} $savings
     * @param array{string, string} $meals
     */
    private static function monthSheet(WorkbookBuilder $workbook, int $month, bool $interest, array $main, array $savings, array $meals): void
    {
        $sheet = $workbook->monthSheet(self::SHEETS[$month - 1])
            ->text('A2', 'Salary')->number('B2', '2500')
            ->text('A3', 'Meal vouchers')->formula('B3', '20*7.5', '150')
            ->text('C2', 'Rent')->number('D2', 6 === $month || 3 === $month ? '800.0000000000001' : '800')
            ->text('E2', 'Food')->formula('F2', '12.5+48.2+7.9', 6 === $month ? '68.60000000000001' : '68.6')
            ->text('E3', 'Fuel')->number('F3', '40')
            ->text('E4', 'Meal voucher spending')->formula('F4', '11.5+9.8', '21.3')
            ->text('E9', 'Gym')
            ->text('E10', '')
            ->text('G2', 'Savings A')->number('H2', '200')
            ->text('G3', 'PEA')->number('H3', '300')
            ->text('J2', 'Main account')->number('K2', $main[0])->formula('L2', 'K2+1', $main[1])
            ->text('J3', 'Savings A')->number('K3', $savings[0])->number('L3', $savings[1])
            ->text('J4', 'Meal vouchers')->number('K4', $meals[0])->number('L4', $meals[1])
            ->text('J5', 'PEA')->number('K5', '0')->number('L5', '0')
            ->text('J6', 'CTO TR')->number('K6', '0')->number('L6', '0');
        if ($interest) {
            $sheet->text('A4', 'Savings A interest')->number('B4', '0.54');
        }
        if (3 === $month) {
            $sheet->text('E5', 'Food')->formula('F5', '20+15-5', '30');
        }
    }

    private static function portfolioSheets(WorkbookBuilder $workbook): void
    {
        $pea = $workbook->sheet('PEA')->text('D2', 'MSCI World')
            ->text('A3', 'Décembre')->number('B3', '0')->number('C3', '0')->number('E3', '0');
        $cto = $workbook->sheet('CTO_TR')->text('D2', 'Tech ETF')->text('E2', 'Bond Fund')
            ->text('A3', 'Décembre')->number('B3', '0')->number('C3', '0')->number('H3', '0');
        foreach (self::SHEETS as $index => $name) {
            $row = (string) ($index + 4);
            $pea->text('A'.$row, $name)->number('B'.$row, '14.64')->number('C'.$row, '300')->formula('D'.$row, '3*95.12', '285.36')->number('E'.$row, (string) (285 * ($index + 1)));
            $cto->text('A'.$row, $name)->number('B'.$row, '10')->number('C'.$row, '175')->number('D'.$row, '100')->formula('E'.$row, '50+25', '75')->number('H'.$row, (string) (160 * ($index + 1)));
        }
    }
}
