import type { Account } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountMultiSelect } from './AccountMultiSelect';

function account(id: string, label: string): Account {
  return { id, label, assetCode: 'EUR', openedOn: '2026-01-01', status: 'ACTIVE' } as Account;
}

const accounts = [
  account('a1', 'Compte courant'),
  account('a2', 'Livret A'),
  account('a3', 'Compte joint'),
];

describe('AccountMultiSelect', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('opens the list on focus and shows every account', () => {
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={vi.fn()}
        value={[]}
      />,
    );

    fireEvent.focus(screen.getByRole('combobox', { name: 'Filtrer par compte' }));

    expect(screen.getByRole('option', { name: 'Compte courant' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Livret A' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Compte joint' })).toBeTruthy();
  });

  it('narrows the list as the user types', () => {
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={vi.fn()}
        value={[]}
      />,
    );

    const input = screen.getByRole('combobox', { name: 'Filtrer par compte' });
    fireEvent.focus(input);
    fireEvent.change(input, { target: { value: 'compte' } });

    expect(screen.getByRole('option', { name: 'Compte courant' })).toBeTruthy();
    expect(screen.getByRole('option', { name: 'Compte joint' })).toBeTruthy();
    expect(screen.queryByRole('option', { name: 'Livret A' })).toBeNull();
  });

  it('adds an account to the selection on click and keeps the list open for another pick', () => {
    const onChange = vi.fn();
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={onChange}
        value={[]}
      />,
    );

    fireEvent.focus(screen.getByRole('combobox', { name: 'Filtrer par compte' }));
    fireEvent.mouseDown(screen.getByRole('option', { name: 'Livret A' }));

    expect(onChange).toHaveBeenCalledWith(['a2']);
    expect(screen.getByRole('option', { name: 'Compte courant' })).toBeTruthy();
  });

  it('removes an already selected account on a second click', () => {
    const onChange = vi.fn();
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={onChange}
        value={['a1', 'a2']}
      />,
    );

    fireEvent.focus(screen.getByRole('combobox', { name: 'Filtrer par compte' }));
    fireEvent.mouseDown(screen.getByRole('option', { name: 'Livret A' }));

    expect(onChange).toHaveBeenCalledWith(['a1']);
  });

  it('shows the number of selected accounts beside the label', () => {
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={vi.fn()}
        value={['a1', 'a2']}
      />,
    );

    expect(screen.getByText('2')).toBeTruthy();
  });

  it('toggles the highlighted account with the keyboard and closes on Escape', () => {
    const onChange = vi.fn();
    render(
      <AccountMultiSelect
        accounts={accounts}
        label="Filtrer par compte"
        onChange={onChange}
        value={[]}
      />,
    );

    const input = screen.getByRole('combobox', { name: 'Filtrer par compte' });
    fireEvent.focus(input);
    fireEvent.keyDown(input, { key: 'Enter' });

    expect(onChange).toHaveBeenCalledWith(['a1']);

    fireEvent.keyDown(input, { key: 'Escape' });
    expect(screen.queryByRole('listbox')).toBeNull();
  });
});
