import type { AnnualReport } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { cleanup, render as baseRender, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { goalEvaluation, goalPeriod } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import '@/i18n';
import { annualReport } from '@/features/reports/annual/annualFixtures';
import tableStyles from '@/features/reports/annual/AnnualReportTable.module.css';
import { ContinuousDetailedReport } from './ContinuousDetailedReport';
import localStyles from './ContinuousDetailedReport.module.css';
import { multiYearReport } from './multiYearFixtures';

function render(element: ReactElement) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return baseRender(<QueryClientProvider client={client}>{element}</QueryClientProvider>);
}

function annualReportForYear(year: number): AnnualReport {
  return {
    ...annualReport,
    year,
    rows: annualReport.rows.map((row) => ({
      ...row,
      month: `${year}${row.month.slice(4)}`,
    })) as AnnualReport['rows'],
  };
}

afterEach(() => {
  cleanup();
});

describe('ContinuousDetailedReport', () => {
  it('hides cached goals and does not request them when goal marks are disabled', () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    client.setQueryData(['annual-report-preferences'], {
      columns: ['cashIncome'],
      incompleteMonths: 'exclude',
      goalMarks: false,
      version: 1,
    });
    client.setQueryData(['goal-evaluations', '2024-01', '2024-12', 'BOTH'], {
      months: [goalPeriod('2024-08', goalEvaluation())],
      years: [],
    });
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    baseRender(
      <QueryClientProvider client={client}>
        <ContinuousDetailedReport
          annualReports={[annualReportForYear(2024)]}
          report={{ ...multiYearReport, years: [multiYearReport.years[0]!] }}
        />
      </QueryClientProvider>,
    );
    expect(screen.queryByRole('button', { name: /^Objectif :/ })).toBeNull();
    expect(fetchSpy).not.toHaveBeenCalled();
    fetchSpy.mockRestore();
  });

  it('wraps its table in a vertically scrollable viewport while keeping the shared horizontal scroll', () => {
    const { container } = render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const scroll = container.querySelector(`.${tableStyles.scroll}`);
    expect(scroll).toBeTruthy();
    expect(scroll!.className.split(' ')).toContain(localStyles.verticalScroll);
  });

  it('marks only December rows with the year-boundary class', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    const bodyRows = within(table.querySelector('tbody')!).getAllByRole('row');
    const boundaryRows = bodyRows.filter((row) =>
      row.className.split(' ').includes(localStyles.yearBoundary),
    );
    expect(boundaryRows).toHaveLength(2);
    expect(boundaryRows[0]?.textContent).toMatch(/décembre 2024/i);
    expect(boundaryRows[1]?.textContent).toMatch(/décembre 2025/i);
  });

  it('still marks the December row when December itself has no data', () => {
    const year = annualReportForYear(2026);
    const futureDecember: AnnualReport = {
      ...year,
      rows: year.rows.map((row, index) =>
        index === 11
          ? {
              ...row,
              state: 'FUTURE',
              cells: { ...row.cells, cashIncome: { value: null, reason: 'FUTURE_MONTH' } },
            }
          : row,
      ) as AnnualReport['rows'],
    };
    render(<ContinuousDetailedReport annualReports={[futureDecember]} report={multiYearReport} />);
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    const bodyRows = within(table.querySelector('tbody')!).getAllByRole('row');
    const boundaryRows = bodyRows.filter((row) =>
      row.className.split(' ').includes(localStyles.yearBoundary),
    );
    expect(boundaryRows).toHaveLength(1);
    expect(boundaryRows[0]?.textContent).toMatch(/décembre 2026/i);
  });

  it('renders the server-provided whole-period aggregate footer verbatim, without computing it', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    const footer = table.querySelector('tfoot');
    expect(footer).toBeTruthy();
    expect(within(footer!).getAllByText('Total').length).toBeGreaterThan(0);
    expect(within(footer!).getAllByText('Moyenne').length).toBeGreaterThan(0);
    expect(within(footer!).getAllByText('Médiane').length).toBeGreaterThan(0);
    expect(within(footer!).getAllByText(/190\s?000,00\s?€/).length).toBeGreaterThan(0);
    expect(within(footer!).getAllByText(/1\s?500,00\s?€/).length).toBeGreaterThan(0);
  });

  it('shows the extreme figure with both its month and its year, not the month alone', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    const footer = table.querySelector('tfoot')!;
    expect(within(footer).getAllByText(/janvier 2026/i).length).toBeGreaterThan(0);
  });

  it('shows a bare dash, without its reason, for a non-calculable footer cell', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={{
          ...multiYearReport,
          detailedPeriod: {
            ...multiYearReport.detailedPeriod,
            cashIncome: {
              ...multiYearReport.detailedPeriod.cashIncome,
              total: null,
              totalReason: 'EMPTY_POPULATION',
            },
          },
        }}
      />,
    );
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    const footer = table.querySelector('tfoot')!;
    const totalRow = within(footer).getAllByRole('row')[0]!;
    expect(within(totalRow).getAllByText('-').length).toBeGreaterThan(0);
    expect(totalRow.textContent).not.toMatch(/aucun mois comptabilisé/i);
  });

  it('shows the same aggregate values in the mobile summary card', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const cards = screen.getByRole('list', { name: /mois de/i });
    const items = within(cards).getAllByRole('listitem');
    const summaryCard = items[items.length - 1]!;
    expect(summaryCard.textContent).toMatch(/190\s?000,00\s?€/);
  });
});
