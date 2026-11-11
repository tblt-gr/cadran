import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { CatalogueEntry } from '@/features/reports/annual/useColumnCatalogue';
import { CategoryColumnOptions } from './CategoryColumnOptions';

afterEach(cleanup);

function category(
  id: string,
  label: string,
  parentId: string | null,
  sortOrder: number,
  effectiveColor: string | null = null,
): CatalogueEntry {
  return { id, label, category: { parentId, sortOrder, effectiveColor } };
}

function renderOptions(entries: CatalogueEntry[] | null, selected: string[] = [], full = false) {
  const onToggle = vi.fn();
  render(
    <CategoryColumnOptions entries={entries} full={full} onToggle={onToggle} selected={selected} />,
  );
  return { onToggle };
}

describe('CategoryColumnOptions', () => {
  it('nests descendants under their parent, siblings by sort order, every category once', () => {
    renderOptions([
      category('b', 'Transport', null, 2),
      category('a2', 'Restaurants', 'a', 2),
      category('a', 'Alimentation', null, 1, '#AA5500'),
      category('a1', 'Courses', 'a', 1),
      category('a1x', 'Bio', 'a1', 1),
    ]);
    const boxes = screen.getAllByRole('checkbox');
    expect(boxes.map((box) => box.getAttribute('aria-label'))).toEqual([
      'Alimentation',
      'Alimentation › Courses',
      'Alimentation › Courses › Bio',
      'Alimentation › Restaurants',
      'Transport',
    ]);
    const root = screen.getByRole('checkbox', { name: 'Alimentation' }).closest('li')!;
    const nested = root.querySelector(':scope > ul') as HTMLElement;
    expect(within(nested).getAllByRole('checkbox')).toHaveLength(3);
    expect(root.querySelector('span[aria-hidden]')?.getAttribute('style')).toContain(
      'rgb(170, 85, 0)',
    );
  });

  it('tells identical names apart by their ancestry', () => {
    renderOptions([
      category('a', 'Maison', null, 1),
      category('b', 'Voiture', null, 2),
      category('a1', 'Autre', 'a', 1),
      category('b1', 'Autre', 'b', 1),
    ]);
    expect(screen.getByRole('checkbox', { name: 'Maison › Autre' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: 'Voiture › Autre' })).toBeTruthy();
  });

  it('keeps a child whose parent is missing as a selectable root', () => {
    renderOptions([category('c', 'Orpheline', 'gone', 1)]);
    expect(screen.getByRole('checkbox', { name: 'Orpheline' })).toBeTruthy();
  });

  it('selects only the clicked category, never its parent or descendants', () => {
    const { onToggle } = renderOptions([
      category('a', 'Alimentation', null, 1),
      category('a1', 'Courses', 'a', 1),
    ]);
    fireEvent.click(screen.getByRole('checkbox', { name: 'Alimentation' }));
    expect(onToggle).toHaveBeenCalledTimes(1);
    expect(onToggle).toHaveBeenCalledWith('category:a');
  });

  it('shows archived selections as checked', () => {
    renderOptions([category('a', 'Ancienne', null, 1)], ['category:a']);
    expect((screen.getByRole('checkbox', { name: 'Ancienne' }) as HTMLInputElement).checked).toBe(
      true,
    );
  });

  it('disables unchecked options at the limit but keeps checked ones removable', () => {
    renderOptions(
      [category('a', 'Un', null, 1), category('b', 'Deux', null, 2)],
      ['category:a'],
      true,
    );
    expect((screen.getByRole('checkbox', { name: 'Un' }) as HTMLInputElement).disabled).toBe(false);
    expect((screen.getByRole('checkbox', { name: 'Deux' }) as HTMLInputElement).disabled).toBe(
      true,
    );
  });

  it('draws disabled placeholders in the final structure while loading', () => {
    renderOptions(null);
    const boxes = screen.getAllByRole('checkbox') as HTMLInputElement[];
    expect(boxes.length).toBeGreaterThan(0);
    expect(boxes.every((box) => box.disabled)).toBe(true);
  });

  it('states when there is no category', () => {
    renderOptions([]);
    expect(screen.getByText('Aucun élément.')).toBeTruthy();
  });
});
