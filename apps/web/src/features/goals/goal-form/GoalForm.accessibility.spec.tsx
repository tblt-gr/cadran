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
    for (const reference of id!.split(/\s+/)) {
      expect(document.getElementById(reference)?.textContent).toBeTruthy();
    }
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
  'explains the positive income share percentage constraint for %s',
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
    fireEvent.change(screen.getByLabelText('Type de valeur'), {
      target: { value: 'INCOME_SHARE' },
    });
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

it.each(['100.01', '-1', '0.0000001'])(
  'explains the target rate constraint for %s, a zero floor staying valid',
  (value) => {
    const onSubmit = vi.fn();
    render(
      <QueryClientProvider
        client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
      >
        <GoalForm
          baseCurrency="EUR"
          pending={false}
          submitError={null}
          submitMismatch={null}
          onSubmit={onSubmit}
        />
      </QueryClientProvider>,
    );
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Taux' } });
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'SAVINGS_RATE' } });
    const rate = screen.getByLabelText('Taux cible');
    fireEvent.change(rate, { target: { value } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(rate.getAttribute('aria-invalid')).toBe('true');
    expect(document.getElementById(rate.getAttribute('aria-describedby')!)?.textContent).toMatch(
      /entre 0 et 100 %/,
    );
    expect(onSubmit).not.toHaveBeenCalled();

    fireEvent.change(rate, { target: { value: '0' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).toHaveBeenCalledWith(
      expect.objectContaining({ valueType: 'RATE', ratio: '0.00' }),
    );
  },
);
