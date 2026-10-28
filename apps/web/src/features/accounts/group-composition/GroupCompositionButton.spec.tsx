import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupCompositionButton } from './GroupCompositionButton';
import { PARENT_ID, workedExample } from './compositionFixtures';

const api = vi.hoisted(() => ({ readAccountGroupComposition: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderButton() {
  api.readAccountGroupComposition.mockResolvedValue({
    data: workedExample(),
    response: new Response('{}', { status: 200 }),
  });
  render(
    <QueryClientProvider client={new QueryClient()}>
      <GroupCompositionButton asOf="2026-10-25" groupId={PARENT_ID} label="Investissement" />
    </QueryClientProvider>,
  );
}

describe('GroupCompositionButton', () => {
  it('opens the composition and returns focus to the pie button on Escape and close', async () => {
    renderButton();
    const open = screen.getByRole('button', { name: 'Répartition de Investissement' });
    expect(open.getAttribute('title')).toBe('Répartition de Investissement');
    open.focus();
    fireEvent.click(open);

    await screen.findByRole('dialog', { name: 'Répartition : Investissement' }, { timeout: 5000 });
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(document.activeElement).toBe(open));

    fireEvent.click(open);
    fireEvent.click(await screen.findByRole('button', { name: 'Fermer' }));
    await waitFor(() => expect(document.activeElement).toBe(open));
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
