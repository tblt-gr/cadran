import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { ClosureBlockerList } from './ClosureBlockerList';

afterEach(() => {
  cleanup();
  window.history.replaceState(null, '', '/');
});

describe('ClosureBlockerList', () => {
  it('names each blocking condition with its count and where to resolve it', () => {
    render(
      <ClosureBlockerList
        blockers={[
          { condition: 'UNRECONCILED_ACCOUNT', count: 2 },
          { condition: 'UNEXPLAINED_DISCREPANCY', count: 1 },
          { condition: 'PENDING_TRANSACTIONS', count: 3 },
          { condition: 'MISSING_PORTFOLIO_VALUATION', count: 1 },
        ]}
        period="2026-02"
      />,
    );

    const items = within(screen.getByRole('list')).getAllByRole('listitem');
    expect(items.map((item) => item.querySelector('span')?.textContent)).toEqual([
      '2 comptes non rapprochés sur ce mois.',
      '1 écart de solde non expliqué.',
      '3 transactions en attente dans ce mois.',
      '1 portefeuille sans valorisation de fin de mois.',
    ]);
    expect(screen.getByRole('link', { name: 'Rapprocher les comptes' }).getAttribute('href')).toBe(
      '/accounts',
    );
    expect(screen.getByRole('link', { name: 'Voir les comptes' }).getAttribute('href')).toBe(
      '/accounts',
    );
    expect(screen.getByRole('link', { name: 'Voir les portefeuilles' }).getAttribute('href')).toBe(
      '/accounts',
    );
  });

  it('links the pending transactions filtered on the whole month, leap February included', () => {
    render(
      <ClosureBlockerList
        blockers={[{ condition: 'PENDING_TRANSACTIONS', count: 1 }]}
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
        blockers={[{ condition: 'PENDING_TRANSACTIONS', count: 2 }]}
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
