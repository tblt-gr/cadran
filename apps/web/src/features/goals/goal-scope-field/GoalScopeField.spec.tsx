import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalScopeField } from './GoalScopeField';

const api = vi.hoisted(() => ({ listAccounts: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

function renderField(props: Partial<React.ComponentProps<typeof GoalScopeField>> = {}) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <GoalScopeField
        scope=""
        scopeType="AXIS"
        scopeValid={false}
        setScope={vi.fn()}
        showErrors={false}
        {...props}
      />
    </QueryClientProvider>,
  );
}

describe('GoalScopeField', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('renders an axis picker for an axis-scoped metric', () => {
    renderField({ scopeType: 'AXIS' });
    expect(screen.getByRole('combobox')).toBeTruthy();
  });

  it('renders nothing for a workspace-scoped metric', () => {
    const { container } = renderField({ scopeType: 'WORKSPACE' });
    expect(container.textContent).toBe('');
  });

  it('loads and lists accounts for an account-scoped metric', async () => {
    api.listAccounts.mockResolvedValue({
      data: { items: [{ id: 'a1', label: 'Compte courant' }] },
      response: new Response(null, { status: 200 }),
    });
    renderField({ scopeType: 'ACCOUNT' });
    await waitFor(() => expect(screen.getByText('Compte courant')).toBeTruthy());
  });
});
