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

  it('shows one heading per day with the weekday, the date and the exact day net', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
        transactions={[
          tx('a', '2026-09-11', '-0.10'),
          tx('b', '2026-09-11', '-0.20'),
          tx('c', '2026-09-10', '5.00'),
        ]}
      />,
    );

    const friday = screen.getByRole('heading', { name: /vendredi 11 septembre 2026/ });
    expect(within(friday).getByLabelText('Sortie de 0,30 €')).toBeTruthy();
    const thursday = screen.getByRole('heading', { name: /jeudi 10 septembre 2026/ });
    expect(within(thursday).getByLabelText('Entrée de 5,00 €')).toBeTruthy();
    expect(screen.getAllByRole('list').length).toBeGreaterThan(1);
    expect(screen.getAllByRole('listitem').length).toBeGreaterThanOrEqual(5);
  });

  it('hides the total of a day that may continue on the next page', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore
        transactions={[tx('a', '2026-09-11', '-1.00'), tx('b', '2026-09-10', '-2.00')]}
      />,
    );

    const last = screen.getByRole('heading', { name: /jeudi 10 septembre 2026/ });
    expect(within(last).queryByRole('img')).toBeNull();
    expect(within(last).queryByText('Non calculable')).toBeNull();
    expect(within(last).getByText('Jour incomplet, chargez la suite')).toBeTruthy();
    const first = screen.getByRole('heading', { name: /vendredi 11 septembre 2026/ });
    expect(within(first).getByRole('img')).toBeTruthy();
  });

  it('says a mixed-currency day is not computable instead of showing zero', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
        transactions={[
          tx('a', '2026-09-11', '1.00'),
          tx('b', '2026-09-11', '1.00', { amount: { value: '1.00', assetCode: 'USD' } }),
        ]}
      />,
    );

    const heading = screen.getByRole('heading', { name: /vendredi 11 septembre 2026/ });
    expect(within(heading).getByText('Non calculable')).toBeTruthy();
  });

  it('shows label, account, state and a sign that does not rely on colour', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
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
    render(<TransactionDayList hasMore={false} transactions={[tx('a', '2026-09-11', '-12.00')]} />);

    expect(screen.queryByText('Compte indisponible')).toBeNull();
  });

  it('keeps a voided movement visible, flagged and out of the day net', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
        transactions={[
          tx('a', '2026-09-11', '-10.00'),
          tx('b', '2026-09-11', '-90.00', { state: 'VOIDED' }),
        ]}
      />,
    );

    expect(screen.getByText('Annulée')).toBeTruthy();
    const heading = screen.getByRole('heading', { name: /vendredi 11 septembre 2026/ });
    expect(within(heading).getByLabelText('Sortie de 10,00 €')).toBeTruthy();
  });

  it('renders a loading list that mirrors the loaded markup element for element', () => {
    const { container, rerender } = render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
        transactions={[
          tx('a', '2026-09-11', '-1.00'),
          tx('b', '2026-09-11', '-1.00'),
          tx('c', '2026-09-11', '-1.00'),
        ]}
      />,
    );
    const loaded = signature(container.querySelector('ol > li') as Element);
    rerender(<TransactionDayList accounts={accounts} hasMore={false} transactions={null} />);
    const loading = signature(container.querySelector('ol > li') as Element);

    expect(loading).toBe(loaded);
    expect(screen.getByTestId('transaction-day-list-skeleton').getAttribute('aria-busy')).toBe(
      'true',
    );
    expect(container.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
  });

  it('keeps the transfer hint on a linked transfer leg', () => {
    render(
      <TransactionDayList
        accounts={accounts}
        hasMore={false}
        transactions={[
          tx('a', '2026-09-11', '-10.00', { nature: 'TRANSFER', transferId: 'pair' }),
          tx('b', '2026-09-11', '-1.00'),
        ]}
      />,
    );

    expect(screen.getAllByText('Virement', { selector: 'small' })).toHaveLength(1);
    expect(
      screen.getAllByText('Utilisez le virement pour modifier ou annuler ce mouvement.'),
    ).toHaveLength(1);
  });

  it('labels the day total as filtered when a filter or a search narrows the rows', () => {
    const rows = [tx('a', '2026-09-11', '-1.00')];
    const { rerender } = render(
      <TransactionDayList accounts={accounts} hasMore={false} transactions={rows} />,
    );
    expect(screen.queryByText('Total filtré')).toBeNull();

    rerender(
      <TransactionDayList accounts={accounts} filtered hasMore={false} transactions={rows} />,
    );

    expect(screen.getByText('Total filtré')).toBeTruthy();
    expect(screen.queryByText('Total du jour')).toBeNull();
  });

  it.each([
    ['amount', [tx('a', '2026-09-11', '-1.00')], false],
    ['partial', [tx('a', '2026-09-11', '-1.00')], true],
    ['none', [tx('a', '2026-09-11', '-1.00', { state: 'VOIDED' })], false],
    [
      'not computable',
      [
        tx('a', '2026-09-11', '-1.00'),
        tx('b', '2026-09-11', '1', { amount: { value: '1', assetCode: 'USD' } }),
      ],
      false,
    ],
  ])('keeps the day header shape of the skeleton for a %s total', (_name, rows, hasMore) => {
    const { container, rerender } = render(
      <TransactionDayList accounts={accounts} hasMore={hasMore} transactions={rows} />,
    );
    const loaded = signature(container.querySelector('h3') as Element);
    rerender(<TransactionDayList accounts={accounts} hasMore={false} transactions={null} />);

    expect(signature(container.querySelector('h3') as Element)).toBe(loaded);
  });
});
