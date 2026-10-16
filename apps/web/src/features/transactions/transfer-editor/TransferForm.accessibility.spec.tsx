import type { Account } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionForm } from '@/features/transactions/transaction-form/TransactionForm';
import { TransferForm } from './TransferForm';

afterEach(cleanup);
it('associates invalid transfer controls with their own error text', () => {
  const accounts = [
    {
      id: 'bank',
      label: 'Banque',
      assetCode: 'EUR',
      status: 'ACTIVE',
      kind: 'CURRENT',
      valuationMode: 'TRANSACTIONS',
    },
  ] as Account[];
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <TransferForm
        accounts={accounts}
        onSubmit={vi.fn()}
        onRetryDeposit={vi.fn()}
        transferCreated={false}
        depositError={null}
        pending={false}
        submitError={null}
      />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
  const invalid = document.querySelectorAll('[aria-invalid="true"]');
  expect(invalid.length).toBeGreaterThan(0);
  for (const field of invalid) {
    const id = field.getAttribute('aria-describedby');
    expect(id).toBeTruthy();
    expect(document.getElementById(id!)?.textContent?.trim().length).toBeGreaterThan(0);
  }
});

it('associates each invalid transaction field with its own error text', () => {
  const accounts = [
    {
      id: 'bank',
      label: 'Banque',
      assetCode: 'EUR',
      status: 'ACTIVE',
      kind: 'CURRENT',
      valuationMode: 'TRANSACTIONS',
    },
  ] as Account[];
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <TransactionForm accounts={accounts} onSubmit={vi.fn()} pending={false} submitError={null} />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  const invalid = document.querySelectorAll('[aria-invalid="true"]');
  expect(invalid.length).toBeGreaterThan(0);
  const descriptions = new Set<string>();
  for (const field of invalid) {
    const id = field.getAttribute('aria-describedby');
    expect(id).toBeTruthy();
    expect(document.getElementById(id!)?.textContent?.trim().length).toBeGreaterThan(0);
    expect(descriptions.has(id!)).toBe(false);
    descriptions.add(id!);
  }
});

function validTransfer() {
  const accounts = [
    { id: 'bank', label: 'Banque', assetCode: 'EUR', openedOn: '2020-01-01', status: 'ACTIVE' },
    { id: 'savings', label: 'Épargne', assetCode: 'EUR', openedOn: '2020-01-01', status: 'ACTIVE' },
  ] as Account[];
  const onSubmit = vi.fn();
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <TransferForm
        accounts={accounts}
        onSubmit={onSubmit}
        onRetryDeposit={vi.fn()}
        transferCreated={false}
        depositError={null}
        pending={false}
        submitError={null}
      />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getByLabelText('Montant (EUR)'), { target: { value: '25.00' } });
  fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
  return onSubmit;
}

it('explains a whitespace-padded note on an otherwise valid transfer and lets it be corrected', () => {
  const submit = validTransfer();
  const note = screen.getByLabelText('Note');
  fireEvent.change(note, { target: { value: ' note' } });
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
  expect(submit).not.toHaveBeenCalled();
  expect(note.getAttribute('aria-invalid')).toBe('true');
  const errorId = note.getAttribute('aria-describedby');
  expect(document.getElementById(errorId!)?.textContent).toMatch(
    /500.*sans espaces au début ni à la fin/,
  );
  expect(document.querySelectorAll('[aria-invalid="true"]')).toHaveLength(1);
  fireEvent.change(note, { target: { value: 'note' } });
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
  expect(submit).toHaveBeenCalledOnce();
});

it('describes the value-date correction as a 90-day window around the booked date', () => {
  const submit = validTransfer();
  fireEvent.change(screen.getByLabelText('Date', { exact: true }), {
    target: { value: '2026-06-01' },
  });
  const date = screen.getByLabelText('Date de valeur');
  fireEvent.change(date, { target: { value: '2026-08-31' } });
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
  expect(submit).not.toHaveBeenCalled();
  expect(date.getAttribute('aria-invalid')).toBe('true');
  const description = document.getElementById(date.getAttribute('aria-describedby')!)?.textContent;
  expect(description).toMatch(/date valide.*90 jours avant ou après la date du virement/);
  expect(description).not.toContain('non future');
  fireEvent.change(date, { target: { value: '2026-08-30' } });
  fireEvent.click(screen.getByRole('button', { name: 'Créer le virement' }));
  expect(submit).toHaveBeenCalledOnce();
});
