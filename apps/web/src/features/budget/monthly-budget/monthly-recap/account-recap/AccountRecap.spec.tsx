import type { MonthlyRecapAccount, MonthlyRecapGroup } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountRecap } from './AccountRecap';
import { MonthlyRecapSkeleton } from '@/features/budget/monthly-budget/monthly-recap/MonthlyRecapSkeleton';

const share = { ratio: null, percent: null, percentDisplay: null, reason: null };

function account(id: string, label: string, institution: string | null): MonthlyRecapAccount {
  return {
    accountId: id,
    label,
    institution,
    kind: 'CURRENT',
    netWorthSign: 1,
    primaryGroupId: 'group-1',
    primaryGroupLabel: 'Liquidités',
    primaryGroupColor: '#FFFFFF',
    eligible: true,
    previousValue: null,
    currentValue: null,
    share,
  };
}

const group: MonthlyRecapGroup = {
  groupId: 'group-1',
  color: '#FFFFFF',
  label: 'Liquidités',
  parentId: null,
  depth: 1,
  value: null,
  previousValue: null,
  share,
};

function renderRecap(accounts: MonthlyRecapAccount[]) {
  return render(
    <AccountRecap
      accounts={accounts}
      currentAsOf="2026-03-15"
      groups={[group]}
      previousAsOf="2026-02-28"
    />,
  );
}

describe('AccountRecap', () => {
  afterEach(() => {
    cleanup();
  });

  it('keeps the order received from the API', () => {
    renderRecap([account('b', 'Zèbre', null), account('a', 'Alpha', null)]);

    const rows = screen.getAllByRole('row').map((row) => row.textContent);
    expect(rows.findIndex((text) => text?.includes('Zèbre'))).toBeLessThan(
      rows.findIndex((text) => text?.includes('Alpha')),
    );
  });

  it('shows the institution under the label without replacing it', () => {
    renderRecap([account('a', 'Compte courant', 'Banque Exemple')]);

    const cell = screen.getByRole('link', { name: 'Compte courant' }).closest('th')!;
    const institution = within(cell).getByText('Banque Exemple');
    expect(institution.tagName).toBe('SMALL');
    expect(institution.getAttribute('title')).toBe('Banque Exemple');
    expect(institution.previousElementSibling?.tagName).toBe('A');
  });

  it('renders no institution line for an account without one', () => {
    renderRecap([account('a', 'Compte courant', null)]);

    const cell = screen.getByRole('link', { name: 'Compte courant' }).closest('th')!;
    expect(cell.querySelector('small')).toBeNull();
  });

  it('mirrors the institution line in the skeleton', () => {
    renderRecap([account('a', 'Compte courant', 'Banque Exemple')]);
    const loaded = screen.getByRole('link', { name: 'Compte courant' }).closest('th')!;
    cleanup();
    const { container } = render(<MonthlyRecapSkeleton status="Chargement" />);
    const row = container.querySelector('tbody:last-of-type tr:not(:first-child) th')!;

    const shape = (cell: Element) =>
      [...cell.children].map((child) => `${child.tagName}.${child.className}`);
    expect(shape(row)).toHaveLength(2);
    expect(row.children[1]?.tagName).toBe('SMALL');
    expect(row.children[1]?.querySelector('[data-skeleton]')).toBeTruthy();
    expect(shape(row)).toEqual(shape(loaded));
  });
});
