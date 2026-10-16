import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryForm } from './CategoryForm';
afterEach(cleanup);
it('associates category validation with the invalid label', () => {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <CategoryForm pending={false} submitError={null} onCancel={vi.fn()} onSubmit={vi.fn()} />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  const input = document.querySelector('[aria-invalid="true"]')!;
  const id = input.getAttribute('aria-describedby');
  expect(id).toBeTruthy();
  expect(document.getElementById(id!)?.textContent).toBeTruthy();
});
