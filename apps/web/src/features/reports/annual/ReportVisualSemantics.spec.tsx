import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { AnnualCellValue } from './AnnualCellValue';
import { FLOW_CHART_COLORS } from './FlowsChart';
import { annualReport } from './annualFixtures';
import { multiYearReport } from '@/features/reports/multi-year/multiYearFixtures';
import { netWorthAssetCode } from '@/features/reports/multi-year/MultiYearCharts';

describe('report visual semantics', () => {
  it('uses the specified chart colors for income, expenses and surplus', () => {
    expect(FLOW_CHART_COLORS).toEqual({
      cashIncome: 'var(--positive)',
      budgetExpenses: 'var(--negative)',
      budgetSurplus: 'var(--chart-allocation-1)',
    });
  });

  it('gives positive, negative and zero values distinct token-backed tones', () => {
    const { container } = render(
      <>
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="1" />
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="-1" />
        <AnnualCellValue assetCode="EUR" kind="FLOW" reason={null} value="0" />
      </>,
    );
    const values = container.querySelectorAll('span');
    expect(values[0]?.className).toContain('positive');
    expect(values[1]?.className).toContain('negative');
    expect(values[2]?.className).toContain('zero');
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
});
