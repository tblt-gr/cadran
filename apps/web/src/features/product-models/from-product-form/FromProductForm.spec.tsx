import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { FromProductForm } from './FromProductForm';
const catalog = vi.hoisted(() => ({
  items: [],
  isPending: true,
  isError: false,
  refetch: vi.fn(),
}));
vi.mock('@/features/product-models/useCatalogProducts', () => ({
  useCatalogProducts: () => catalog,
}));
afterEach(cleanup);
it('keeps its fields and submit action visible while loading and after a catalog error', () => {
  const props = { pending: false, submitError: null, onCancel: vi.fn(), onSubmit: vi.fn() };
  const view = render(<FromProductForm {...props} />);
  expect(screen.getByText('Nom du modèle')).toBeTruthy();
  expect(screen.getByText('Produit du catalogue')).toBeTruthy();
  expect(
    screen.getByRole('button', { name: 'Créer le modèle' }).closest('fieldset')?.disabled,
  ).toBe(true);
  expect(view.container.querySelectorAll('[data-skeleton]').length).toBe(1);
  catalog.isPending = false;
  catalog.isError = true;
  view.rerender(<FromProductForm {...props} />);
  expect(screen.getByRole('alert')).toBeTruthy();
  expect(screen.getByText('Nom du modèle')).toBeTruthy();
  expect(
    screen.getByRole('button', { name: 'Créer le modèle' }).closest('fieldset')?.disabled,
  ).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  expect(catalog.refetch).toHaveBeenCalledOnce();
});
