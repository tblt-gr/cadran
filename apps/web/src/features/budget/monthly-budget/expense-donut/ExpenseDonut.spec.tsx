import type { MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { ExpenseDonut } from './ExpenseDonut';

afterEach(cleanup);

const period = { from: '2026-10-01', to: '2026-10-31' };
const ID_RENT = '11111111-1111-4111-8111-111111111111';
const ID_FOOD = '22222222-2222-4222-8222-222222222222';

function row(
  id: string,
  label: string,
  value: string | null,
  overrides: Partial<MonthlyLedgerCategoryRow> = {},
): MonthlyLedgerCategoryRow {
  return {
    archived: false,
    budgetIncluded: true,
    color: null,
    hasMovements: true,
    icon: null,
    id,
    label,
    movementCount: 1,
    total: { assetCode: value === null ? null : 'EUR', reason: null, value },
    ...overrides,
  };
}

function structure(element: Element): string {
  return Array.from(element.children)
    .map((child) => `${child.tagName}(${structure(child)})`)
    .join('');
}

describe('ExpenseDonut', () => {
  it('lists every slice with its exact amount, share and a link to the filtered transactions', () => {
    render(
      <ExpenseDonut
        axis={null}
        period={period}
        rows={[
          row(ID_FOOD, 'Courses', '300.50', { color: '#aabbcc' }),
          row(ID_RENT, 'Loyer', '600.00'),
        ]}
      />,
    );

    const list = screen.getByRole('list', { name: 'Dépenses du mois par catégorie' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(2);
    expect(items[0]!.textContent).toContain('Loyer');
    expect(items[0]!.textContent).toContain('66,63');
    expect(items[1]!.textContent).toContain('33,37');
    const link = screen.getByRole('link', { name: 'Afficher les transactions de Loyer' });
    const url = new URL(link.getAttribute('href')!, 'https://cadran.test');
    expect(url.pathname).toBe('/transactions');
    expect(url.searchParams.getAll('categoryId')).toEqual([ID_RENT]);
    expect(url.searchParams.get('from')).toBe('2026-10-01');
    expect(url.searchParams.get('to')).toBe('2026-10-31');
    expect(url.searchParams.get('period')).toBe('custom');
  });

  it('carries the analytic axis into the link', () => {
    render(<ExpenseDonut axis="FIXED" period={period} rows={[row(ID_RENT, 'Loyer', '10.00')]} />);

    const link = screen.getByRole('link', { name: 'Afficher les transactions de Loyer' });
    expect(
      new URL(link.getAttribute('href')!, 'https://cadran.test').searchParams.get('axis'),
    ).toBe('FIXED');
  });

  it('groups the smaller categories into "Autres" with the count', () => {
    const rows = [100, 90, 80, 70, 60, 50, 40, 30].map((amount) =>
      row(`00000000-0000-4000-8000-0000000000${amount}`, `Cat ${amount}`, `${amount}.00`),
    );
    render(<ExpenseDonut axis={null} period={period} rows={rows} />);

    const list = screen.getByRole('list', { name: 'Dépenses du mois par catégorie' });
    const items = within(list).getAllByRole('listitem');
    expect(items).toHaveLength(7);
    expect(items[6]!.textContent).toContain('Autres (2 catégories)');
  });

  it('says so when the month has no budget expense', () => {
    render(<ExpenseDonut axis={null} period={period} rows={[]} />);

    expect(screen.getByRole('status').textContent).toBe(
      'Aucune dépense dans le budget ce mois-ci.',
    );
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('shows a non-calculable reason, never a zero, for mixed assets', () => {
    render(<ExpenseDonut axis={null} period={period} rows={[row(ID_RENT, 'Loyer', null)]} />);

    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.queryByText(/0,00/)).toBeNull();
  });

  it('mirrors the loaded structure while rows are pending, with the static chrome real', () => {
    const { container: loaded } = render(
      <ExpenseDonut
        axis={null}
        period={period}
        rows={[row(ID_RENT, 'Loyer', '100.00'), row(ID_FOOD, 'Courses', '50.00')]}
      />,
    );
    const { container: pending } = render(<ExpenseDonut axis={null} period={period} rows={null} />);

    expect(
      within(pending as HTMLElement).getByRole('heading', { name: 'Répartition des dépenses' }),
    ).toBeTruthy();
    const loadedSection = loaded.querySelector('section')!;
    const pendingSection = pending.querySelector('section')!;
    expect(pendingSection.querySelector('h3')!.textContent).toBe(
      loadedSection.querySelector('h3')!.textContent,
    );
    expect(pendingSection.querySelector('p')!.textContent).toBe(
      loadedSection.querySelector('p')!.textContent,
    );
    const rowTags = (section: Element) =>
      Array.from(section.querySelectorAll('ul > li')).map((li) =>
        Array.from(li.children)
          .map((child) => child.tagName)
          .join(','),
      );
    expect(new Set(rowTags(pendingSection))).toEqual(new Set(rowTags(loadedSection)));
    expect(pendingSection.querySelector('ul')!.getAttribute('aria-label')).toBe(
      loadedSection.querySelector('ul')!.getAttribute('aria-label'),
    );
    expect(structure(pendingSection.querySelector('li')!)).toContain('SPAN');
  });
});
