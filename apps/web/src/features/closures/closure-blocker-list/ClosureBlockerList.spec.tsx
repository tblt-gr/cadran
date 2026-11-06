import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { PeriodClosingBlockerItem } from '@cadran/api-client';
import { ClosureBlockerList } from './ClosureBlockerList';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

function item(overrides: Partial<PeriodClosingBlockerItem>): PeriodClosingBlockerItem {
  return {
    accountId: 'acc-1',
    label: 'Courant',
    kind: 'CURRENT',
    valuationMode: 'SNAPSHOTS',
    reason: 'NO_BALANCE_IN_MONTH',
    snapshotAsOf: null,
    discrepancy: null,
    ...overrides,
  };
}

const courant = item({});
const livret = item({
  accountId: 'acc-2',
  label: 'Livret A',
  kind: 'SAVINGS',
  reason: 'BALANCE_NOT_RECONCILED',
  snapshotAsOf: '2026-10-31',
});
const joint = item({
  accountId: 'acc-3',
  label: 'Joint',
  reason: 'BALANCE_NOT_RECONCILED',
  snapshotAsOf: '2026-10-31',
});
const jointGap = item({
  accountId: 'acc-3',
  label: 'Joint',
  reason: 'DISCREPANCY',
  snapshotAsOf: '2026-10-31',
  discrepancy: { value: '12.40', assetCode: 'EUR' },
});
const portfolio = item({
  accountId: 'acc-4',
  label: 'Compte-titres',
  kind: 'PORTFOLIO',
  valuationMode: 'PORTFOLIO',
  reason: 'NO_MONTH_END_VALUATION',
});

function lines(section: HTMLElement) {
  return within(section)
    .getAllByRole('listitem')
    .map((row) => row.textContent);
}

describe('ClosureBlockerList', () => {
  it('lists each blocking account with its precise reason and its fix', () => {
    render(
      <ClosureBlockerList
        blockers={[
          { condition: 'UNRECONCILED_ACCOUNT', count: 3, items: [courant, livret, joint] },
          { condition: 'UNEXPLAINED_DISCREPANCY', count: 1, items: [jointGap] },
          { condition: 'PENDING_TRANSACTIONS', count: 3, items: [] },
          { condition: 'MISSING_PORTFOLIO_VALUATION', count: 1, items: [portfolio] },
        ]}
        period="2026-10"
      />,
    );

    const sections = screen.getAllByRole('group');
    expect(sections).toHaveLength(3);
    expect(screen.getByText('3 comptes non rapprochés sur ce mois.')).toBeTruthy();
    expect(lines(sections[0]!)).toEqual([
      'CourantAucun solde relevé ce mois-ciSaisir le solde',
      'Livret ASolde du 31 octobre 2026 non rapprochéRapprocher',
      'JointSolde du 31 octobre 2026 non rapprochéRapprocher',
    ]);
    expect(lines(sections[1]!)).toEqual([
      'JointÉcart de +12,40\u00a0€ au 31 octobre 2026Voir l\u2019écart',
    ]);
    expect(lines(sections[2]!)).toEqual(
      ['Compte-titresAucune valorisation de fin de mois'].map(
        (row) => `${row}Saisir une valorisation`,
      ),
    );
  });

  it('points each action at its account with the month end as asOf', () => {
    render(
      <ClosureBlockerList
        blockers={[
          { condition: 'UNRECONCILED_ACCOUNT', count: 2, items: [courant, livret] },
          { condition: 'UNEXPLAINED_DISCREPANCY', count: 1, items: [jointGap] },
          { condition: 'MISSING_PORTFOLIO_VALUATION', count: 1, items: [portfolio] },
        ]}
        period="2028-02"
      />,
    );
    const href = (name: string) => screen.getByRole('link', { name }).getAttribute('href');

    expect(href('Saisir le solde')).toBe(
      '/accounts/acc-1?tab=balance&action=record-balance&asOf=2028-02-29',
    );
    expect(href('Rapprocher')).toBe('/accounts/acc-2?tab=balance&action=reconcile&asOf=2028-02-29');
    expect(href('Voir l\u2019écart')).toBe(
      '/accounts/acc-3?tab=balance&action=reconcile&asOf=2028-02-29',
    );
    expect(href('Saisir une valorisation')).toBe(
      '/portfolios/acc-4?tab=valuations&action=record-valuation&asOf=2028-02-29',
    );
    expect(href('Courant')).toBe('/accounts/acc-1');
    expect(href('Compte-titres')).toBe('/portfolios/acc-4');
  });

  it('says a valuation is not published when the month end one is not calculated', () => {
    render(
      <ClosureBlockerList
        blockers={[
          {
            condition: 'MISSING_PORTFOLIO_VALUATION',
            count: 1,
            items: [
              { ...portfolio, reason: 'VALUATION_NOT_CALCULATED', snapshotAsOf: '2026-10-30' },
            ],
          },
        ]}
        period="2026-10"
      />,
    );

    expect(screen.getByText('Valorisation du 30 octobre 2026 non publiée')).toBeTruthy();
  });

  it('shows a negative gap with its minus sign', () => {
    render(
      <ClosureBlockerList
        blockers={[
          {
            condition: 'UNEXPLAINED_DISCREPANCY',
            count: 1,
            items: [{ ...jointGap, discrepancy: { value: '-10', assetCode: 'EUR' } }],
          },
        ]}
        period="2026-10"
      />,
    );

    expect(screen.getByText(/Écart de [-\u2212]10,00\s€ au 31 octobre 2026/)).toBeTruthy();
  });

  it('opens a disclosure of at most five accounts and folds a longer one', () => {
    const many = (count: number) =>
      Array.from({ length: count }, (_, index) =>
        item({ accountId: `acc-${index}`, label: `Compte ${index}` }),
      );
    const first = render(
      <ClosureBlockerList
        blockers={[{ condition: 'UNRECONCILED_ACCOUNT', count: 5, items: many(5) }]}
        period="2026-10"
      />,
    );
    expect(first.container.querySelector('details')?.open).toBe(true);
    cleanup();

    const second = render(
      <ClosureBlockerList
        blockers={[{ condition: 'UNRECONCILED_ACCOUNT', count: 6, items: many(6) }]}
        period="2026-10"
      />,
    );
    expect(second.container.querySelector('details')?.open).toBe(false);
  });

  it('keeps the whole-list link for a blocker that carries no account', () => {
    render(
      <ClosureBlockerList
        blockers={[{ condition: 'UNRECONCILED_ACCOUNT', count: 2, items: [] }]}
        period="2026-02"
      />,
    );

    expect(screen.getByText('2 comptes non rapprochés sur ce mois.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Rapprocher les comptes' }).getAttribute('href')).toBe(
      '/accounts',
    );
    expect(screen.queryByRole('group')).toBeNull();
  });

  it('navigates in place from an account action and lets the caller dismiss its modal', () => {
    const onNavigate = vi.fn();
    render(
      <ClosureBlockerList
        blockers={[{ condition: 'UNRECONCILED_ACCOUNT', count: 1, items: [courant] }]}
        onNavigate={onNavigate}
        period="2026-10"
      />,
    );

    fireEvent.click(screen.getByRole('link', { name: 'Saisir le solde' }));

    expect(window.location.pathname).toBe('/accounts/acc-1');
    expect(window.location.search).toBe('?tab=balance&action=record-balance&asOf=2026-10-31');
    expect(onNavigate).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('link', { name: 'Courant' }));
    expect(onNavigate).toHaveBeenCalledTimes(2);
  });

  it('links the pending transactions filtered on the whole month, leap February included', () => {
    render(
      <ClosureBlockerList
        blockers={[{ condition: 'PENDING_TRANSACTIONS', count: 1, items: [] }]}
        period="2028-02"
      />,
    );

    expect(screen.getByText('1 transaction en attente dans ce mois.')).toBeTruthy();
    const href = screen
      .getByRole('link', { name: 'Voir les transactions en attente' })
      .getAttribute('href');
    expect(href).toBe('/transactions?period=custom&from=2028-02-01&to=2028-02-29&state=PENDING');
  });

  it('navigates in place and lets the caller dismiss its modal', () => {
    const onNavigate = vi.fn();
    render(
      <ClosureBlockerList
        blockers={[{ condition: 'PENDING_TRANSACTIONS', count: 2, items: [] }]}
        onNavigate={onNavigate}
        period="2026-12"
      />,
    );

    fireEvent.click(screen.getByRole('link', { name: 'Voir les transactions en attente' }));

    expect(window.location.pathname).toBe('/transactions');
    expect(window.location.search).toBe(
      '?period=custom&from=2026-12-01&to=2026-12-31&state=PENDING',
    );
    expect(onNavigate).toHaveBeenCalledTimes(1);
  });

  it('renders nothing without blockers', () => {
    const { container } = render(<ClosureBlockerList blockers={[]} period="2026-12" />);

    expect(container.innerHTML).toBe('');
  });
});
