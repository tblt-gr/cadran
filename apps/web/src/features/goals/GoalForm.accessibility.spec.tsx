import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalForm } from './GoalForm';
afterEach(cleanup);
it('describes invalid goal fields with the visible validation message', () => {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <GoalForm
        baseCurrency="EUR"
        pending={false}
        submitError={null}
        submitMismatch={null}
        onSubmit={vi.fn()}
      />
    </QueryClientProvider>,
  );
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  const fields = document.querySelectorAll('[aria-invalid="true"]');
  expect(fields.length).toBeGreaterThan(0);
  for (const field of fields) {
    const id = field.getAttribute('aria-describedby');
    expect(id).toBeTruthy();
    expect(document.getElementById(id!)?.textContent).toBeTruthy();
  }
});

it.each(['0', '-1', '0.000000001'])(
  'explains the exact positive annual target constraint for %s',
  (value) => {
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <GoalForm
          baseCurrency="EUR"
          pending={false}
          submitError={null}
          submitMismatch={null}
          onSubmit={vi.fn()}
        />
      </QueryClientProvider>,
    );
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    const amount = screen.getByLabelText('Montant cible');
    expect(amount.getAttribute('aria-invalid')).toBe('true');
    const description = document.getElementById(
      amount.getAttribute('aria-describedby')!,
    )?.textContent;
    expect(description).toMatch(/montant décimal exact strictement supérieur à 0/);
    expect(description).toMatch(/8 décimales/);
  },
);

it.each(['0', '100.01', '0.0000001'])(
  'explains the positive rolling percentage constraint for %s',
  (value) => {
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <GoalForm
          baseCurrency="EUR"
          pending={false}
          submitError={null}
          submitMismatch={null}
          onSubmit={vi.fn()}
        />
      </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole('radio', { name: 'Ratio glissant' }));
    const amount = screen.getByLabelText('Pourcentage');
    fireEvent.change(amount, { target: { value } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(amount.getAttribute('aria-invalid')).toBe('true');
    const description = document.getElementById(
      amount.getAttribute('aria-describedby')!,
    )?.textContent;
    expect(description).toMatch(/strictement supérieur à 0 et inférieur ou égal à 100/);
    expect(description).toMatch(/6 décimales/);
  },
);
