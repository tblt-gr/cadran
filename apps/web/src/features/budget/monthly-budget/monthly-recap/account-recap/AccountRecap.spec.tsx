import type { MonthlyRecapAccount, MonthlyRecapGroup } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
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
  directValue: null,
  directPreviousValue: null,
  directShare: share,
  hasChildren: false,
};

function renderRecap(accounts: MonthlyRecapAccount[], groups: MonthlyRecapGroup[] = [group]) {
  return render(
    <AccountRecap
      accounts={accounts}
      currentAsOf="2026-03-15"
      groups={groups}
      previousAsOf="2026-02-28"
    />,
  );
}

describe('AccountRecap', () => {
  beforeEach(() => {
    window.localStorage.clear();
  });
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

  describe('grouping', () => {
    const amount = (value: string) => ({
      value,
      assetCode: 'EUR',
      display: null,
      belowDisplayStep: false,
    });
    const invest: MonthlyRecapGroup = {
      ...group,
      groupId: 'inv',
      label: 'Investissement',
      value: amount('300'),
      previousValue: amount('250'),
      directValue: amount('100'),
      directPreviousValue: amount('90'),
      hasChildren: true,
    };
    const bourse: MonthlyRecapGroup = {
      ...group,
      groupId: 'bourse',
      label: 'Bourse',
      parentId: 'inv',
      depth: 2,
      value: amount('200'),
      previousValue: amount('160'),
      directValue: amount('200'),
      directPreviousValue: amount('160'),
    };
    const accounts = [
      { ...account('a-inv', 'Livret inv', 'Banque A'), primaryGroupId: 'inv' },
      { ...account('a-bourse', 'PEA', 'Banque B'), primaryGroupId: 'bourse' },
      { ...account('a-none', 'Cash', null), primaryGroupId: null },
    ];

    it('defaults to sub-group mode with the parent in parentheses', () => {
      renderRecap(accounts, [invest, bourse]);

      const rowgroups = [...document.querySelectorAll('th[scope="rowgroup"]')];
      expect(rowgroups.map((h) => h.textContent)).toEqual([
        'Investissement',
        'Bourse (Investissement)',
        'Sans groupe principal',
      ]);
      expect(
        screen
          .getByRole('button', { name: 'Détailler par sous-groupe' })
          .getAttribute('aria-pressed'),
      ).toBe('true');
    });

    it('rolls up in parent mode, captions sub-group accounts and remembers the choice', () => {
      renderRecap(accounts, [invest, bourse]);
      fireEvent.click(screen.getByRole('button', { name: 'Détailler par sous-groupe' }));

      const rowgroups = [...document.querySelectorAll('th[scope="rowgroup"]')];
      expect(rowgroups.map((h) => h.textContent)).toEqual([
        'Investissement',
        'Sans groupe principal',
      ]);
      const cell = screen.getByRole('link', { name: 'PEA' }).closest('th')!;
      expect(within(cell).getByText('Bourse (Investissement)').tagName).toBe('SMALL');
      const inv = screen.getByRole('link', { name: 'Livret inv' }).closest('th')!;
      expect(inv.querySelectorAll('small')).toHaveLength(1);
      expect(window.localStorage.getItem('cadran.budget.recap.grouping')).toBe('PARENT');

      cleanup();
      renderRecap(accounts, [invest, bourse]);
      expect(
        screen
          .getByRole('button', { name: 'Détailler par sous-groupe' })
          .getAttribute('aria-pressed'),
      ).toBe('false');
    });

    it('mirrors the toggle, disabled, in the skeleton', () => {
      render(<MonthlyRecapSkeleton status="Chargement" />);

      const toggle = screen.getByRole('button', { name: 'Détailler par sous-groupe' });
      expect((toggle as HTMLButtonElement).disabled).toBe(true);
    });
  });
});
