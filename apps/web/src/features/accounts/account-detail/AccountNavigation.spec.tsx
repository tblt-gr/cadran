import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountNavigation } from './AccountNavigation';

afterEach(cleanup);

describe('AccountNavigation', () => {
  it('links the account and operations tabs and marks the active one', () => {
    render(<AccountNavigation accountId="a1" operations />);
    expect(screen.getByRole('navigation', { name: 'Sections du compte' })).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Compte' }).getAttribute('href')).toBe(
      '/accounts/a1?tab=account',
    );
    const operations = screen.getByRole('link', { name: 'Opérations' });
    expect(operations.getAttribute('href')).toBe('/accounts/a1?tab=operations');
    expect(operations.getAttribute('aria-current')).toBe('page');
    expect(screen.getByRole('link', { name: 'Compte' }).getAttribute('aria-current')).toBeNull();
  });
});
