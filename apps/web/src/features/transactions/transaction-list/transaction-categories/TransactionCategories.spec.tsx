import type { TransactionSplit } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { TransactionCategories } from './TransactionCategories';

function split(label: string, value: string, index: number): TransactionSplit {
  return {
    id: `00000000-0000-7000-8000-00000000000${index}`,
    categoryId: `00000000-0000-7000-8000-0000000000c${index}`,
    categoryLabel: label,
    categoryIcon: null,
    categoryColor: null,
    amount: { value, assetCode: 'EUR' },
    analyticAxes: [],
    note: null,
    categorizationOrigin: 'MANUAL',
    categorizationRuleId: null,
  };
}

describe('TransactionCategories', () => {
  afterEach(cleanup);

  it('asks for a category when the transaction has none', () => {
    render(<TransactionCategories splits={[]} />);

    expect(screen.getByText('À catégoriser')).toBeTruthy();
  });

  it('shows one pill for a single category, without a split breakdown', () => {
    render(<TransactionCategories splits={[split('Courses', '-42.90', 1)]} />);

    expect(screen.getByText('Courses')).toBeTruthy();
    expect(screen.queryByRole('list')).toBeNull();
  });

  it('lists every category of a split with its share', () => {
    render(
      <TransactionCategories
        splits={[
          split('Courses', '-62.10', 1),
          split('Maison', '-18.30', 2),
          split('Plaisirs', '-7.00', 3),
        ]}
      />,
    );

    expect(screen.getByText('Répartie en 3 catégories')).toBeTruthy();
    const rows = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(
      rows.map((row) => Array.from(row.children, (part) => part.textContent?.replace(/\s/g, ' '))),
    ).toEqual([
      ['Courses', '62,10 €'],
      ['Maison', '18,30 €'],
      ['Plaisirs', '7,00 €'],
    ]);
  });

  it('counts the rows beyond the visible ones instead of stretching the table row', () => {
    render(
      <TransactionCategories
        splits={[
          split('Courses', '-40.00', 1),
          split('Maison', '-20.00', 2),
          split('Plaisirs', '-10.00', 3),
          split('Santé', '-5.00', 4),
        ]}
      />,
    );

    expect(screen.getByText('Répartie en 4 catégories')).toBeTruthy();
    expect(within(screen.getByRole('list')).getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByText('+ 2 autres catégories')).toBeTruthy();
  });
});
