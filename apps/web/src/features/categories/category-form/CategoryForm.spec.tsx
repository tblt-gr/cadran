import type { Category } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryForm } from './CategoryForm';

const category: Category = {
  id: '00000000-0000-7000-8000-0000000000c1',
  type: 'EXPENSE',
  label: 'Restaurants',
  parentId: null,
  parentLabel: null,
  icon: 'utensils',
  color: '#AABBCC',
  defaultAnalyticAxes: ['DISCRETIONARY'],
  budgetIncluded: true,
  sortOrder: 10,
  depth: 1,
  version: 3,
  used: true,
  typeEditable: false,
  typeEditReason: 'USED',
  canAcceptChildren: true,
  archivedAt: null,
  replacement: null,
};

function form(value = category) {
  const onSubmit = vi.fn();
  render(
    <CategoryForm
      category={value}
      onCancel={vi.fn()}
      onSubmit={onSubmit}
      pending={false}
      submitError={null}
    />,
  );
  return onSubmit;
}

describe('CategoryForm identity', () => {
  afterEach(cleanup);

  it('prefills the edit selection and previews each label, icon and colour change', () => {
    const onSubmit = form();
    const preview = screen.getByRole('status', { name: 'Aperçu de la catégorie' });
    expect((screen.getByRole('radio', { name: 'Couverts' }) as HTMLInputElement).checked).toBe(
      true,
    );
    expect((screen.getByLabelText('Code couleur') as HTMLInputElement).value).toBe('#AABBCC');
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: ' Sorties ' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Tasse' }));
    fireEvent.change(screen.getByLabelText('Code couleur'), { target: { value: '#bada55' } });
    expect(within(preview).getByText('Sorties')).toBeTruthy();
    expect(preview.querySelector('[data-icon="coffee"]')).toBeTruthy();
    expect((preview.querySelector('[data-icon="coffee"]') as HTMLElement).style.background).toBe(
      'rgb(186, 218, 85)',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).toHaveBeenCalledWith({
      type: 'EXPENSE',
      label: 'Sorties',
      color: '#BADA55',
      icon: 'coffee',
      defaultAnalyticAxes: ['DISCRETIONARY'],
      budgetIncluded: true,
      sortOrder: 10,
      version: 3,
    });
  });

  it('previews invalid and empty colour explicitly and prevents saving invalid metadata', () => {
    const onSubmit = form();
    const preview = screen.getByRole('status', { name: 'Aperçu de la catégorie' });
    fireEvent.change(screen.getByLabelText('Code couleur'), { target: { value: '#zzzzzz' } });
    expect(within(preview).getByText(/Couleur invalide : aperçu neutre/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('checkbox', { name: 'Donner une couleur à cette catégorie' }));
    fireEvent.click(screen.getByRole('radio', { name: 'Sans icône' }));
    expect(within(preview).getByText('Sans couleur · Sans icône')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ color: null, icon: null }));
  });

  it('requires an explicit repair of an unknown historical icon before saving', () => {
    const onSubmit = form({ ...category, icon: 'old-unavailable-key' });
    expect(screen.getByText(/Cette ancienne icône/)).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('radio', { name: 'Sans icône' }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(onSubmit).toHaveBeenCalledWith(expect.objectContaining({ icon: null }));
  });
});
