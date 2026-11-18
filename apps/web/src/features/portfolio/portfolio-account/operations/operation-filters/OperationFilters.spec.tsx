import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { OperationFilters } from './OperationFilters';
import { noFilters, type OperationFilterValues } from './operationFilterValues';

const api = vi.hoisted(() => ({ listInstruments: vi.fn(), readInstrument: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function stubScreen(compact: boolean) {
  vi.stubGlobal('matchMedia', (query: string) => ({
    matches: compact,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
  }));
}

function Harness({ initial = noFilters }: { initial?: OperationFilterValues }) {
  const [value, setValue] = useState(initial);
  return (
    <QueryClientProvider client={new QueryClient()}>
      <OperationFilters currencyCode="EUR" onChange={setValue} value={value} />
    </QueryClientProvider>
  );
}

describe('OperationFilters', () => {
  it('keeps the inputs on screen from 768 px', () => {
    stubScreen(false);
    render(<Harness />);

    expect(screen.getByLabelText('Du')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Filtrer/ })).toBeNull();
  });

  it('hides the inputs behind a Filtrer button up to 767 px', () => {
    stubScreen(true);
    render(<Harness />);

    expect(screen.queryByLabelText('Du')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /Filtrer/ }));
    const sheet = screen.getByRole('dialog', { name: 'Filtres' });
    expect(within(sheet).getByLabelText('Du')).toBeTruthy();
  });

  it('shows the applied filters as chips with their count, each clearable, and resets them all', () => {
    stubScreen(true);
    render(
      <Harness
        initial={{ ...noFilters, from: '2026-03-01', types: ['BUY'], includeVoided: true }}
      />,
    );

    expect(screen.getByRole('button', { name: /Filtrer/ }).textContent).toContain('2');
    expect(screen.getByRole('button', { name: 'Retirer le filtre Achat' })).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Retirer le filtre Achat' }));
    expect(screen.queryByRole('button', { name: 'Retirer le filtre Achat' })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Tout effacer' }));
    expect(screen.queryByRole('group', { name: /filtres/i })).toBeNull();
    expect(screen.getByRole('button', { name: /Filtrer/ }).textContent).not.toMatch(/\d/);
  });

  it('applies a date from the sheet and shows it as a chip once the sheet is closed', () => {
    stubScreen(true);
    render(<Harness />);

    fireEvent.click(screen.getByRole('button', { name: /Filtrer/ }));
    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-03-01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les opérations' }));

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(
      screen.getByRole('button', { name: /^Retirer le filtre Depuis le 01\/03\/2026/ }),
    ).toBeTruthy();
  });
});
