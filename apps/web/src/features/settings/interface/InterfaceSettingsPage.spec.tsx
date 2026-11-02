import type { InterfacePreferences } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { InterfaceSettingsPage } from './InterfaceSettingsPage';
import { InterfaceSettingsPageSkeleton } from './InterfaceSettingsPageSkeleton';

const api = vi.hoisted(() => ({
  readInterfacePreferences: vi.fn(),
  saveInterfacePreferences: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const defaults: InterfacePreferences = { includeUpcoming: true, version: 0 };

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({
    error: { status },
    response: new Response('{}', { status }),
  });
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const invalidate = vi.spyOn(queryClient, 'invalidateQueries');

  return {
    invalidate,
    ...render(
      <QueryClientProvider client={queryClient}>
        <InterfaceSettingsPage />
      </QueryClientProvider>,
    ),
  };
}

async function loadedCheckbox() {
  await waitFor(() =>
    expect(
      (
        screen.getByRole('checkbox', {
          name: /Inclure les transactions à venir/,
        }) as HTMLInputElement
      ).disabled,
    ).toBe(false),
  );
  return screen.getByRole('checkbox', { name: /Inclure les transactions à venir/ });
}

function isChecked() {
  return (
    screen.getByRole('checkbox', { name: /Inclure les transactions à venir/ }) as HTMLInputElement
  ).checked;
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('InterfaceSettingsPage', () => {
  it('shows the upcoming checkbox on by default once loaded', async () => {
    api.readInterfacePreferences.mockReturnValue(ok(defaults));
    renderPage();

    const checkbox = await loadedCheckbox();
    expect((checkbox as HTMLInputElement).checked).toBe(true);
    expect((checkbox as HTMLInputElement).disabled).toBe(false);
    expect(screen.getByText(/Le patrimoine et les soldes ne changent pas/)).toBeTruthy();
  });

  it('saves immediately with the stored version, confirms and refreshes monthly figures', async () => {
    api.readInterfacePreferences.mockReturnValue(ok(defaults));
    api.saveInterfacePreferences.mockReturnValue(ok({ includeUpcoming: false, version: 1 }));
    const { invalidate } = renderPage();

    fireEvent.click(await loadedCheckbox());

    await waitFor(() =>
      expect(api.saveInterfacePreferences).toHaveBeenCalledWith(
        expect.objectContaining({ body: { includeUpcoming: false, version: 0 } }),
      ),
    );
    expect((await screen.findByRole('status')).textContent).toBe(
      'La préférence a été enregistrée.',
    );
    expect(isChecked()).toBe(false);
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['monthly-report'] });
    expect(invalidate).toHaveBeenCalledWith({ queryKey: ['budget-comparisons'] });
  });

  it('refetches and explains a stale version', async () => {
    api.readInterfacePreferences.mockReturnValue(ok({ includeUpcoming: true, version: 3 }));
    api.saveInterfacePreferences.mockReturnValue(failure(409));
    renderPage();

    fireEvent.click(await loadedCheckbox());

    expect((await screen.findByRole('alert')).textContent).toMatch(/modifié ailleurs/);
    await waitFor(() => expect(api.readInterfacePreferences).toHaveBeenCalledTimes(2));
  });

  it('keeps the previous value when the save fails', async () => {
    api.readInterfacePreferences.mockReturnValue(ok(defaults));
    api.saveInterfacePreferences.mockReturnValue(failure(500));
    renderPage();

    fireEvent.click(await loadedCheckbox());

    expect((await screen.findByRole('alert')).textContent).toMatch(
      /valeur précédente est conservée/,
    );
    await waitFor(() => expect(isChecked()).toBe(true));
  });

  it('offers a retry when the preferences cannot be read', async () => {
    api.readInterfacePreferences.mockReturnValue(failure(500));
    renderPage();

    expect((await screen.findByRole('alert')).textContent).toContain('Préférences indisponibles');
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('renders the real tabs in order and a disabled checkbox while loading', () => {
    api.readInterfacePreferences.mockReturnValue(new Promise(() => undefined));
    renderPage();

    const skeleton = screen.getByTestId('interface-settings-skeleton');
    expect(skeleton.getAttribute('aria-busy')).toBe('true');
    expect(
      (
        screen.getByRole('checkbox', {
          name: /Inclure les transactions à venir/,
        }) as HTMLInputElement
      ).disabled,
    ).toBe(true);
    const tabs = screen.getAllByRole('link').map((link) => link.textContent);
    expect(tabs).toEqual([
      'Interface',
      'Catalogue',
      'Modèles',
      'Politique d’indicateurs',
      'Profil',
    ]);
  });

  it('mirrors the loaded page: same card heading and checkbox', async () => {
    api.readInterfacePreferences.mockReturnValue(ok(defaults));
    const { unmount } = render(
      <QueryClientProvider client={new QueryClient()}>
        <InterfaceSettingsPageSkeleton />
      </QueryClientProvider>,
    );
    const skeletonHeading = screen.getByRole('heading', { level: 3 }).textContent;
    unmount();
    renderPage();
    await loadedCheckbox();
    expect(screen.getByRole('heading', { level: 3 }).textContent).toBe(skeletonHeading);
  });
});
