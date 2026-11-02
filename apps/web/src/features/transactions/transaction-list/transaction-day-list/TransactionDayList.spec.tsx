import type { Account, Transaction } from '@cadran/api-client';
import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { TransactionDayList } from './TransactionDayList';

const accounts = [{ id: 'acc-1', label: 'Compte courant' }] as Account[];

function tx(id: string, bookedOn: string, value: string, extra: Partial<Transaction> = {}) {
  return {
    id,
    accountId: 'acc-1',
    bookedOn,
    amount: { value, assetCode: 'EUR' },
    nature: 'EXPENSE',
    state: 'BOOKED',
    rawLabel: `Libellé ${id}`,
    displayLabel: `Libellé ${id}`,
    splits: [],
    transferId: null,
    refundOriginalLabel: null,
    refundedAmount: null,
    reviewReason: null,
    ...extra,
  } as Transaction;
}

/** Tags and classes of the markup; value leaves (skeleton blocks, amounts) are not compared. */
function signature(element: Element): string {
  const own = `${element.tagName.toLowerCase()}.${element.getAttribute('class') ?? ''}`;
  const children = Array.from(element.children)
    .filter((child) => !child.hasAttribute('data-skeleton') && child.getAttribute('role') !== 'img')
    .map(signature);
  return children.length === 0 ? own : `${own}(${children.join(',')})`;
}

describe('TransactionDayList', () => {
  afterEach(cleanup);

  it('shows one heading per day with the weekday and the date, and no day total', () => {
    render(
      <TransactionDayList
        accounts={accounts}

        transactions={[
          tx('a', '2026-09-11', '-0.10'),
          tx('b', '2026-09-11', '-0.20'),
          tx('c', '2026-09-10', '5.00'),
        ]}
      />,
    );

    const friday = screen.getByRole('heading', { name: /vendredi 11 septembre 2026/ });
    const thursday = screen.getByRole('heading', { name: /jeudi 10 septembre 2026/ });
    expect(within(friday).queryByRole('img')).toBeNull();
    expect(within(thursday).queryByRole('img')).toBeNull();
    expect(screen.queryByText(/Total/)).toBeNull();
    expect(
      within(screen.getAllByRole('listitem')[0] as HTMLElement).getAllByRole('heading'),
    ).toHaveLength(1);
    expect(screen.getAllByRole('list').length).toBeGreaterThan(1);
    expect(screen.getAllByRole('listitem').length).toBeGreaterThanOrEqual(5);
  });

  it('shows label, account, state and a sign that does not rely on colour', () => {
    render(
      <TransactionDayList
        accounts={accounts}

        transactions={[tx('a', '2026-09-11', '-12.00', { state: 'PENDING' })]}
      />,
    );

    expect(screen.getByText('Libellé a', { selector: 'button' })).toBeTruthy();
    expect(screen.getByText('Compte courant')).toBeTruthy();
    expect(screen.getByText('En attente')).toBeTruthy();
    for (const amount of screen.getAllByLabelText('Sortie de 12,00 €')) {
      expect(amount.textContent).toContain('-');
    }
  });

  it('omits the account on a list already scoped to one account', () => {
    render(<TransactionDayList transactions={[tx('a', '2026-09-11', '-12.00')]} />);

    expect(screen.queryByText('Compte indisponible')).toBeNull();
  });

  it('renders a loading list that mirrors the loaded markup element for element', () => {
    const { container, rerender } = render(
      <TransactionDayList
        accounts={accounts}

        transactions={[
          tx('a', '2026-09-11', '-1.00'),
          tx('b', '2026-09-11', '-1.00'),
          tx('c', '2026-09-11', '-1.00'),
        ]}
      />,
    );
    const loaded = signature(container.querySelector('ol > li') as Element);
    rerender(<TransactionDayList accounts={accounts} transactions={null} />);
    const loading = signature(container.querySelector('ol > li') as Element);

    expect(loading).toBe(loaded);
    expect(screen.getByTestId('transaction-day-list-skeleton').getAttribute('aria-busy')).toBe(
      'true',
    );
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
  });

  it('marks a linked transfer leg without a category prompt', () => {
    render(
      <TransactionDayList
        accounts={accounts}

        transactions={[
          tx('a', '2026-09-11', '-10.00', { nature: 'TRANSFER', transferId: 'pair' }),
          tx('b', '2026-09-11', '-1.00'),
        ]}
      />,
    );

    expect(screen.getAllByText('Virement', { selector: 'small' })).toHaveLength(1);
    expect(
      screen.queryByText('Utilisez le virement pour modifier ou annuler ce mouvement.'),
    ).toBeNull();
    expect(screen.getAllByText('À catégoriser')).toHaveLength(1);
  });

  it('keeps a voided movement visible and flagged', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        transactions={[tx('b', '2026-09-11', '-90.00', { state: 'VOIDED' })]}
      />,
    );

    expect(screen.getByText('Annulée')).toBeTruthy();
  });

  it('mutes a pending row and keeps its state badge, leaving booked rows unchanged', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        transactions={[
          tx('p', '2026-09-11', '-10.00', { state: 'PENDING' }),
          tx('b', '2026-09-11', '-20.00'),
        ]}
      />,
    );

    const pending = screen.getByText('Libellé p').closest('li')!;
    const booked = screen.getByText('Libellé b').closest('li')!;
    expect(pending.getAttribute('data-state')).toBe('PENDING');
    expect(pending.className).toContain('pending');
    expect(within(pending).getByText('En attente')).toBeTruthy();
    // Every cell the pending style dims is a descendant of the row; the badge is in none of them.
    for (const dimmed of ['primary', 'amount', 'category', 'account']) {
      const cell = pending.querySelector(`[class*="${dimmed}"]`);
      expect(cell, dimmed).toBeTruthy();
      expect(cell?.contains(within(pending).getByText('En attente'))).toBe(false);
    }
    expect(booked.className).not.toContain('pending');
    expect(within(booked).queryByText('En attente')).toBeNull();
  });

  it('says "Prévue le jj/mm" for a planned pending row and keeps "En attente" for the others', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        transactions={[
          tx('p', '2026-11-20', '-29.99', { state: 'PENDING', autoBook: true }),
          tx('q', '2026-11-08', '-5.00', { state: 'PENDING', autoBook: false }),
        ]}
      />,
    );

    expect(screen.getByText('Prévue le 20/11')).toBeTruthy();
    expect(screen.getAllByText('En attente')).toHaveLength(1);
    const planned = screen.getByText('Prévue le 20/11').closest('[data-state]');
    expect(planned?.getAttribute('data-state')).toBe('PENDING');
    expect(planned?.className).toMatch(/planned/);
    expect(screen.getByText('En attente').closest('[data-state]')?.className).not.toMatch(
      /planned/,
    );
  });

  it('drops the mention once the row is booked', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        transactions={[tx('p', '2026-11-20', '-29.99', { state: 'BOOKED', autoBook: false })]}
      />,
    );

    expect(screen.queryByText(/Prévue le/)).toBeNull();
  });
});
