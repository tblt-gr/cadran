import type { Account, Transaction } from '@cadran/api-client';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
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
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it('bounds mounted rows for a long single day and reveals loaded rows by keyboard controls', () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const transactions = Array.from({ length: 1000 }, (_, index) =>
      tx(`long-${index}`, '2026-09-11', '-1.00'),
    );
    const { container } = render(<TransactionDayList transactions={transactions} />);
    expect(container.querySelectorAll('[data-state]').length).toBeLessThan(200);
    expect(screen.getByText('Libellé long-0')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Mouvements suivants déjà chargés' }));
    expect(document.activeElement?.closest('[data-state]')?.textContent).toMatch(/Libellé long-/);
    expect(container.querySelectorAll('[data-state]').length).toBeLessThan(200);
  });

  it('keeps one accessible day heading after the first virtual block leaves the DOM', async () => {
    const scroll = vi.spyOn(window, 'scrollY', 'get').mockReturnValue(0);
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    render(
      <TransactionDayList
        transactions={Array.from({ length: 1000 }, (_, index) =>
          tx(`scroll-${index}`, '2026-09-11', '-1.00'),
        )}
      />,
    );
    scroll.mockReturnValue(8000);
    fireEvent.scroll(window);
    await waitFor(() => expect(screen.queryByText('Libellé scroll-0')).toBeNull());
    expect(screen.getAllByRole('heading', { name: /vendredi 11 septembre 2026/ })).toHaveLength(1);
  });

  it('keeps the visible row DOM and focus when an earlier pinned block is released', async () => {
    const scroll = vi.spyOn(window, 'scrollY', 'get').mockReturnValue(0);
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    render(
      <TransactionDayList
        transactions={Array.from({ length: 1000 }, (_, index) =>
          tx(`focus-${index}`, '2026-09-11', '-1.00'),
        )}
      />,
    );
    const pinned = screen.getByRole('button', {
      name: 'Actions de la transaction « Libellé focus-0 »',
    });
    act(() => pinned.focus());
    scroll.mockReturnValue(8000);
    fireEvent.scroll(window);
    const targetName = 'Actions de la transaction « Libellé focus-100 »';
    const target = await screen.findByRole('button', { name: targetName });
    expect(
      screen.getByRole('button', { name: 'Actions de la transaction « Libellé focus-0 »' }),
    ).toBe(pinned);
    act(() => target.focus());
    await waitFor(() => expect(screen.queryByText('Libellé focus-0')).toBeNull());
    expect(screen.getByRole('button', { name: targetName })).toBe(target);
    expect(document.activeElement).toBe(target);
  });

  it('appends to a day without remounting existing rows or repeating its heading', () => {
    const first = tx('first', '2026-09-11', '-1.00');
    const { rerender } = render(<TransactionDayList transactions={[first]} />);
    const original = screen.getByRole('button', {
      name: 'Actions de la transaction « Libellé first »',
    });
    act(() => original.focus());
    rerender(<TransactionDayList transactions={[first, tx('second', '2026-09-11', '-2.00')]} />);
    expect(
      screen.getByRole('button', { name: 'Actions de la transaction « Libellé first »' }),
    ).toBe(original);
    expect(document.activeElement).toBe(original);
    expect(screen.getAllByRole('heading', { name: /vendredi 11 septembre 2026/ })).toHaveLength(1);
  });

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

  it('writes a transfer as source to destination on both of its legs', () => {
    const two = [...accounts, { id: 'acc-2', label: 'Livret A' }] as Account[];
    render(
      <TransactionDayList
        accounts={two}
        transactions={[
          tx('out', '2026-09-11', '-10.00', { nature: 'TRANSFER', transferId: 'pair' }),
          tx('in', '2026-09-11', '10.00', {
            accountId: 'acc-2',
            nature: 'TRANSFER',
            transferId: 'pair',
          }),
        ]}
      />,
    );

    expect(screen.getAllByText('Compte courant → Livret A', { selector: 'small' })).toHaveLength(2);
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
    // The badge sits under the label with its own tone; the amount and context cells never hold it.
    for (const dimmed of ['amount', 'category', 'account']) {
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
