import type { Account } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { TransactionMainFields } from './TransactionMainFields';
import type { TransactionFormValues } from '@/features/transactions/transaction-form/transactionFormValues';

const account = {
  id: 'account-1',
  label: 'Compte courant',
  assetCode: 'EUR',
  status: 'ACTIVE',
  valuation: { amount: { value: '100.00', assetCode: 'EUR' } },
} as unknown as Account;

const values = {
  accountId: 'account-1',
  amountValue: '',
  bookedOn: '2026-11-10',
  nature: 'EXPENSE',
  paymentMethod: '',
  rawLabel: '',
  userLabel: '',
} as unknown as TransactionFormValues;

function renderFields(overrides: Partial<Parameters<typeof TransactionMainFields>[0]> = {}) {
  const onChange = vi.fn();
  const onAccountChosen = vi.fn();
  render(
    <TransactionMainFields
      accounts={[account]}
      amountAssetCode="EUR"
      balanceAfter={null}
      direction={null}
      editing={false}
      errors={{}}
      onAccountChosen={onAccountChosen}
      onChange={onChange}
      rawLabelLocked={false}
      resolvedAccountId="account-1"
      showErrors={false}
      upcoming={false}
      values={values}
      {...overrides}
    />,
  );
  return { onAccountChosen, onChange };
}

afterEach(cleanup);

it('names every field accessibly', () => {
  renderFields();
  for (const name of ['Compte', /^Date comptable/, 'Libellé', 'Montant']) {
    expect(screen.getByLabelText(name)).toBeTruthy();
  }
});

it('reports the account choice and the typed amount unchanged as a string', () => {
  const { onAccountChosen, onChange } = renderFields();
  fireEvent.change(screen.getByLabelText('Compte'), { target: { value: '' } });
  expect(onAccountChosen).toHaveBeenCalled();
  fireEvent.change(screen.getByLabelText('Montant'), { target: { value: '12.30' } });
  expect(onChange).toHaveBeenCalledWith('amountValue', '12.30');
});

it('flags an invalid amount and links its message', () => {
  renderFields({ errors: { amountValue: true } as never, showErrors: true });
  const input = screen.getByLabelText('Montant');
  expect(input.getAttribute('aria-invalid')).toBe('true');
  expect(input.getAttribute('aria-describedby')).toBeTruthy();
});

it('locks the account and explains why when editing', () => {
  renderFields({ editing: true });
  expect((screen.getByLabelText('Compte') as HTMLSelectElement).disabled).toBe(true);
});

it('shows the read-only raw label beside the editable display label', () => {
  renderFields({ rawLabelLocked: true });
  expect((screen.getByLabelText('Libellé d’origine') as HTMLInputElement).readOnly).toBe(true);
});
