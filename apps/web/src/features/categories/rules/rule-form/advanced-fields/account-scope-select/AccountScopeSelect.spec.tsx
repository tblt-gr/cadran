import type { Account } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AccountScopeSelect } from './AccountScopeSelect';

const accounts = [
  { id: 'a1', label: 'Compte courant', assetCode: 'EUR' },
  { id: 'a2', label: 'Livret A', assetCode: 'EUR' },
] as Account[];

describe('AccountScopeSelect', () => {
  afterEach(cleanup);

  it('reads an empty selection as every account and toggles accounts from the list', () => {
    const onToggle = vi.fn();
    render(
      <AccountScopeSelect
        accounts={accounts}
        onClear={vi.fn()}
        onToggle={onToggle}
        selected={[]}
      />,
    );

    const trigger = screen.getByRole('button', { name: /Comptes concernés Tous les comptes/ });
    expect(trigger.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(trigger);
    expect(trigger.getAttribute('aria-expanded')).toBe('true');
    fireEvent.click(screen.getByRole('checkbox', { name: 'Livret A · EUR' }));
    expect(onToggle).toHaveBeenCalledWith('a2');
  });

  it('summarises the selection and offers to return to every account', () => {
    const onClear = vi.fn();
    const { rerender } = render(
      <AccountScopeSelect
        accounts={accounts}
        onClear={onClear}
        onToggle={vi.fn()}
        selected={['a1']}
      />,
    );
    expect(screen.getByRole('button', { name: /Compte courant/ })).toBeTruthy();

    rerender(
      <AccountScopeSelect
        accounts={accounts}
        onClear={onClear}
        onToggle={vi.fn()}
        selected={['a1', 'a2']}
      />,
    );
    fireEvent.click(screen.getByRole('button', { name: /2 comptes/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Tous les comptes' }));
    expect(onClear).toHaveBeenCalled();
  });

  it('folds on Escape without letting the key reach the hosting modal', () => {
    const documentEscape = vi.fn();
    document.addEventListener('keydown', documentEscape);
    render(
      <AccountScopeSelect accounts={accounts} onClear={vi.fn()} onToggle={vi.fn()} selected={[]} />,
    );

    const trigger = screen.getByRole('button', { name: /Comptes concernés/ });
    fireEvent.click(trigger);
    fireEvent.keyDown(screen.getByRole('checkbox', { name: 'Compte courant · EUR' }), {
      key: 'Escape',
    });

    expect(trigger.getAttribute('aria-expanded')).toBe('false');
    expect(documentEscape).not.toHaveBeenCalled();
    document.removeEventListener('keydown', documentEscape);
  });
});
