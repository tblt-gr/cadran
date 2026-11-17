import type { AnnualReport } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactElement } from 'react';
import { cleanup, fireEvent, render as baseRender, screen, within } from '@testing-library/react';
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
        initiallyExpanded
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
    render(
      <ContinuousDetailedReport
        annualReports={[futureDecember]}
        initiallyExpanded
        report={multiYearReport}
      />,
    );
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

  it('shows the same aggregate values in the mobile summary cards, one folded card per statistic', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const cards = screen.getByRole('list', { name: /mois de/i });
    const items = within(cards).getAllByRole('listitem');
    const totalCard = items.find(
      (item) => within(item).queryByRole('heading', { name: 'Total' }) !== null,
    )!;
    expect(totalCard.textContent).toMatch(/190\s?000,00\s?€/);
    for (const name of ['Total', 'Moyenne', 'Médiane', 'Minimum', 'Maximum']) {
      const card = items.find((item) => within(item).queryByRole('heading', { name }) !== null)!;
      expect(card.querySelector('details')!.open).toBe(false);
    }
  });

  it('folds each year card on its income and expenses and unfolds it into label and value rows', () => {
    render(
      <ContinuousDetailedReport
        annualReports={[annualReportForYear(2024), annualReportForYear(2025)]}
        report={multiYearReport}
      />,
    );
    const cards = screen.getByRole('list', { name: /mois de/i });
    const yearCard = within(cards)
      .getAllByRole('listitem')
      .find((item) => within(item).queryByRole('heading', { name: '2024' }) !== null)!;
    const details = yearCard.querySelector('details')!;
    expect(details.open).toBe(false);
    expect(within(details.querySelector('summary')!).getByText(/revenus/i)).toBeTruthy();
    fireEvent.click(details.querySelector('summary')!);
    expect(details.open).toBe(true);
    expect(details.querySelectorAll('dl > div > dt').length).toBeGreaterThan(0);
    // The change against the previous year does not fit a phone card.
    expect(yearCard.textContent).not.toMatch(/\+2\s?000,00\s?€/);
  });
});

describe('ContinuousDetailedReport year rows', () => {
  const props = {
    annualReports: [annualReportForYear(2024), annualReportForYear(2025)],
    report: { ...multiYearReport, years: multiYearReport.years.slice(0, 2) },
  };

  it('starts folded, each year row already holding the figures of the year', () => {
    render(<ContinuousDetailedReport {...props} />);
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    expect(within(table.querySelector('tbody')!).getAllByRole('row')).toHaveLength(2);
    expect(within(table).getAllByText(/\+2\s?000,00\s?€/).length).toBeGreaterThan(0);
    fireEvent.click(within(table).getByRole('button', { name: '2024' }));
    expect(within(table.querySelector('tbody')!).getAllByRole('row').length).toBeGreaterThan(2);
  });

  it('opens every year when it starts expanded', () => {
    render(<ContinuousDetailedReport {...props} initiallyExpanded />);
    const table = screen.getByRole('table', { name: /rapport détaillé/i });
    expect(within(table.querySelector('tbody')!).getAllByRole('row').length).toBeGreaterThan(2);
  });

  it('hides the change against the previous year unless asked', () => {
    const { rerender } = render(<ContinuousDetailedReport {...props} showChanges={false} />);
    expect(screen.queryByText(/\+2\s?000,00\s?€/)).toBeNull();
    rerender(
      <QueryClientProvider client={new QueryClient()}>
        <ContinuousDetailedReport {...props} showChanges />
      </QueryClientProvider>,
    );
    expect(screen.getAllByText(/\+2\s?000,00\s?€/).length).toBeGreaterThan(0);
  });
});
