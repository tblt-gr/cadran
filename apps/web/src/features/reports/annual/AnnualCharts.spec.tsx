import type { AnnualReport } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AnnualCharts } from './AnnualCharts';
import { annualReport } from './annualFixtures';

const STORAGE_KEY = 'cadran.reports.allocation.grouping';

beforeEach(() => window.localStorage.clear());
afterEach(cleanup);

function group(
  groupId: string,
  label: string,
  parentId: string | null,
  rollup: [string | null, string | null],
  direct: [string | null, string | null],
  color: string | null,
  reason: string | null = null,
) {
  return {
    groupId,
    label,
    parentId,
    depth: parentId === null ? 1 : 2,
    hasChildren: groupId === 'inv',
    value: rollup[0],
    share: rollup[1],
    reason,
    directValue: direct[0],
    directShare: direct[1],
    directReason: reason,
    color,
  };
}

// Investissement (direct 100) holds Bourse 900 and Immobilier 500; Épargne 1000; eligible 2500.
const workedExample: AnnualReport['charts']['allocation'] = {
  asOf: '2026-08-31',
  assetCode: 'EUR',
  reason: null,
  items: [
    group('inv', 'Investissement', null, ['1500.00', '0.60'], ['100.00', '0.04'], '#486DF0'),
    group('bourse', 'Bourse', 'inv', ['900.00', '0.36'], ['900.00', '0.36'], '#2E7D32'),
    group('immo', 'Immobilier', 'inv', ['500.00', '0.20'], ['500.00', '0.20'], null),
    group('epargne', 'Épargne', null, ['1000.00', '0.40'], ['1000.00', '0.40'], '#C62828'),
  ],
};

function renderCharts(allocation = workedExample) {
  const report = {
    ...annualReport,
    charts: { ...annualReport.charts, allocation },
  } satisfies AnnualReport;
  return render(<AnnualCharts includeTrends={false} report={report} />);
}

function allocationRows() {
  const region = screen.getByRole('region', { name: 'Répartition en fin de période' });
  return within(
    within(region).getByRole('list', { name: 'Répartition en fin de période' }),
  ).getAllByRole('listitem');
}

function barFill(row: HTMLElement) {
  return row.querySelector('rect')?.getAttribute('fill') ?? undefined;
}

describe('AnnualCharts category donut', () => {
  it('colors legend bars with the category color, defaulting uncolored ones', () => {
    renderCharts();
    const region = screen.getByRole('region', { name: 'Principales catégories de dépenses' });
    const rows = within(
      within(region).getByRole('list', { name: 'Principales catégories de dépenses' }),
    ).getAllByRole('listitem');
    expect(rows.map(barFill)).toEqual(['#2E7D32', '#1565C0', undefined]);
  });
});

describe('AnnualCharts allocation donut', () => {
  it('defaults to sub-groups, with no parent counted next to its sub-groups', () => {
    renderCharts();
    expect(
      screen
        .getByRole('button', { name: 'Détailler par sous-groupe' })
        .getAttribute('aria-pressed'),
    ).toBe('true');
    const rows = allocationRows();
    expect(rows.map((row) => row.textContent)).toEqual([
      expect.stringContaining('Investissement'),
      expect.stringContaining('Bourse (Investissement)'),
      expect.stringContaining('Immobilier (Investissement)'),
      expect.stringContaining('Épargne'),
    ]);
    expect(rows[0]?.textContent).toContain('100,00');
    expect(rows[0]?.textContent).toContain('4,00');
    expect(rows[0]?.textContent).not.toContain('1 500');
    expect(rows.map(barFill)).toEqual(['#486DF0', '#2E7D32', undefined, '#C62828']);
  });

  it('shows one slice per root group in parent mode and remembers the choice', () => {
    const { unmount } = renderCharts();
    fireEvent.click(screen.getByRole('button', { name: 'Détailler par sous-groupe' }));
    const rows = allocationRows();
    expect(rows).toHaveLength(2);
    expect(rows[0]?.textContent).toContain('1\u202f500,00');
    expect(rows[0]?.textContent).toContain('60,00');
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('PARENT');
    unmount();

    renderCharts();
    expect(
      screen
        .getByRole('button', { name: 'Détailler par sous-groupe' })
        .getAttribute('aria-pressed'),
    ).toBe('false');
    expect(allocationRows()).toHaveLength(2);
  });

  it('keeps the reason of a group that cannot be valued when nothing is calculable', () => {
    renderCharts({
      ...workedExample,
      items: [
        group('crypto', 'Crypto', null, [null, null], [null, null], null, 'MISSING_VALUATION'),
      ],
    });
    const rows = allocationRows();
    expect(rows).toHaveLength(1);
    expect(rows[0]?.textContent).toContain('Crypto');
    expect(within(rows[0]!).getAllByText('-').length).toBe(2);
  });

  it('keeps the empty state free of the toggle behavior', () => {
    renderCharts({ ...workedExample, assetCode: null, reason: 'NO_DATA', items: [] });
    expect(screen.getByText(/non calculable/i)).toBeTruthy();
  });
});

describe('AnnualCharts grouping toggle', () => {
  it('is a focusable button named for sub-group detail, pressed by default', () => {
    renderCharts();
    const toggle = screen.getByRole('button', { name: 'Détailler par sous-groupe' });
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(toggle.getAttribute('type')).toBe('button');
    toggle.focus();
    expect(document.activeElement).toBe(toggle);
  });

  it('persists the choice under the report key and restores it', () => {
    window.localStorage.setItem(STORAGE_KEY, 'PARENT');
    renderCharts();
    const toggle = screen.getByRole('button', { name: 'Détailler par sous-groupe' });
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    fireEvent.click(toggle);
    expect(window.localStorage.getItem(STORAGE_KEY)).toBe('PRIMARY');
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
  });
});

describe('AnnualCharts net worth baseline', () => {
  const point = (month: string, value: string | null) => ({
    month,
    value: { value, reason: value === null ? 'MISSING_VALUATION' : null },
  });
  function renderNetWorth(
    opening: ReturnType<typeof point> | null,
    months: ReturnType<typeof point>[],
  ) {
    const report = {
      ...annualReport,
      charts: {
        ...annualReport.charts,
        netWorth: { assetCode: 'EUR', opening, months },
      },
    } satisfies AnnualReport;
    render(<AnnualCharts report={report} />);
    const region = screen.getByRole('region', { name: 'Patrimoine net' });
    return [...region.querySelectorAll('details tbody tr')].map((row) => row.textContent ?? '');
  }

  it('lists December of the previous year first when there is an opening', () => {
    const rows = renderNetWorth(point('2025-12', '1000.00'), [
      point('2026-01', '850.00'),
      point('2026-02', '900.00'),
    ]);
    expect(rows).toHaveLength(3);
    expect(rows[0]).toMatch(/déc.*2025/i);
    expect(rows[0]).toContain('1\u202f000,00');
    expect(rows[1]).toMatch(/^janvier/i);
    expect(rows[1]).toContain('850,00');
  });

  it('keeps the first data month as the start without an opening', () => {
    const rows = renderNetWorth(null, [point('2026-01', '850.00')]);
    expect(rows).toHaveLength(1);
    expect(rows[0]).toMatch(/^janvier/i);
  });

  it('keeps a December without value as a gap, never zero', () => {
    const rows = renderNetWorth(point('2025-12', null), [point('2026-01', '850.00')]);
    expect(rows).toHaveLength(2);
    expect(rows[0]).toMatch(/déc.*2025/i);
    expect(rows[0]).not.toContain('0,00');
  });
});
