import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AppShell } from '@/components/layout/app-shell/AppShell';

function stubPhone(phone: boolean) {
  vi.stubGlobal(
    'matchMedia',
    vi.fn((query: string) => ({
      matches: phone && query === '(max-width: 767px)',
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })),
  );
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('back arrow', () => {
  it('goes up one level from a detail page on a phone', () => {
    stubPhone(true);
    const navigate = vi.fn();
    render(
      <AppShell path="/accounts/abc" setPath={navigate}>
        <p>Content</p>
      </AppShell>,
    );
    const link = screen.getByRole('link', { name: 'Retour : Comptes' });
    expect(link.getAttribute('href')).toBe('/accounts');
    fireEvent.click(link);
    expect(navigate).toHaveBeenCalledWith('/accounts');
  });

  it('is absent on a list page, the dashboard and from 768 px', () => {
    stubPhone(true);
    const { rerender } = render(
      <AppShell path="/accounts" setPath={vi.fn()}>
        <p>Content</p>
      </AppShell>,
    );
    expect(screen.queryByRole('link', { name: /^Retour/ })).toBeNull();
    rerender(
      <AppShell path="/budget/2026" setPath={vi.fn()}>
        <p>Content</p>
      </AppShell>,
    );
    expect(screen.queryByRole('link', { name: /^Retour/ })).toBeNull();
    stubPhone(false);
    rerender(
      <AppShell path="/accounts/abc" setPath={vi.fn()}>
        <p>Content</p>
      </AppShell>,
    );
    expect(screen.queryByRole('link', { name: /^Retour/ })).toBeNull();
  });
});
