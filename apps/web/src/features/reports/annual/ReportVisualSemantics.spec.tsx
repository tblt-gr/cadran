import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { AnnualCellValue } from './AnnualCellValue';
import {
  FLOW_BAR_SERIES,
  FLOW_CHART_COLORS,
  FLOW_CHART_STACK_OFFSET,
  FLOW_SURPLUS_SERIES,
} from './FlowsChart';
import { annualReport } from './annualFixtures';
import { multiYearReport } from '@/features/reports/multi-year/multiYearFixtures';
import { netWorthAssetCode } from '@/features/reports/multi-year/MultiYearCharts';
import { MultiYearCellValue } from '@/features/reports/multi-year/MultiYearCellValue';

describe('report visual semantics', () => {
  it('uses the specified chart colors for income, expenses and surplus', () => {
    expect(FLOW_CHART_COLORS).toEqual({
      cashIncome: 'var(--positive)',
      budgetExpenses: 'var(--negative)',
      budgetSurplus: 'var(--chart-allocation-1)',
    });
  });

  it('locks the shared stacked bars and blue surplus line used by annual and multi-year charts', () => {
    expect(FLOW_CHART_STACK_OFFSET).toBe('sign');
    expect(FLOW_BAR_SERIES).toEqual([
      { dataKey: 'cashIncome', fill: 'var(--positive)', stackId: 'flows' },
      { dataKey: 'budgetExpenses', fill: 'var(--negative)', stackId: 'flows' },
    ]);
    expect(FLOW_SURPLUS_SERIES).toEqual({
      dataKey: 'budgetSurplus',
      stroke: 'var(--chart-allocation-1)',
    });
  });

  it('keeps primary values neutral', () => {
    const { container } = render(
      <>
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="1" />
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="-1" />
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="0" />
      </>,
    );
    const values = container.querySelectorAll('span');
    expect([...values].every((value) => value.className === '')).toBe(true);
    expect(screen.getAllByText(/1,00/)).toHaveLength(2);
  });

  it('uses the first annual net-worth series with an asset code in detailed mode', () => {
    expect(
      netWorthAssetCode(
        [
          {
            ...annualReport,
            charts: {
              ...annualReport.charts,
              netWorth: { ...annualReport.charts.netWorth, assetCode: null },
            },
          },
          annualReport,
        ],
        multiYearReport,
      ),
    ).toBe('EUR');
  });

  it('colors only signed multi-year variations, keeping their primary values neutral', () => {
    const cell = (change: string) => ({
      value: '100',
      reason: null,
      asOf: null,
      change,
      changeRatio: null,
      changeReason: null,
    });
    const { container } = render(
      <>
        <MultiYearCellValue assetCode="EUR" cell={cell('1')} kind="FLOW" />
        <MultiYearCellValue assetCode="EUR" cell={cell('-1')} kind="FLOW" />
        <MultiYearCellValue assetCode="EUR" cell={cell('0')} kind="FLOW" />
      </>,
    );
    const primary = container.querySelectorAll('span');
    const variations = container.querySelectorAll('small');
    expect([...primary].every((value) => value.className === '')).toBe(true);
    expect(variations[0]?.className).toContain('positive');
    expect(variations[1]?.className).toContain('negative');
    expect(variations[2]?.className).toContain('zero');
  });
});
