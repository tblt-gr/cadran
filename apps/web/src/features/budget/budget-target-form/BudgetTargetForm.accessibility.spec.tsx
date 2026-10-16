import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { BudgetTargetForm } from './BudgetTargetForm';
afterEach(cleanup);
it('describes invalid axis and amount controls with the displayed errors', () => {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <BudgetTargetForm pending={false} submitError={null} onSubmit={vi.fn()} />
    </QueryClientProvider>,
  );
  fireEvent.change(screen.getAllByRole('combobox')[0]!, { target: { value: 'AXIS' } });
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  const fields = document.querySelectorAll('[aria-invalid="true"]');
  expect(fields.length).toBe(2);
  for (const field of fields) {
    const id = field.getAttribute('aria-describedby');
    expect(id).toBeTruthy();
    expect(document.getElementById(id!)?.textContent).toBeTruthy();
  }
});
