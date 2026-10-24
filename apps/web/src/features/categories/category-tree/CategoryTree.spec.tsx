import type { Category } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryTree } from './CategoryTree';

function make(id: string, label: string, parentId: string | null, depth: number, extra = {}) {
  return {
    id,
    type: 'EXPENSE',
    label,
    parentId,
    parentLabel: null,
    icon: null,
    color: null,
    effectiveColor: null,
    effectiveIcon: null,
    defaultAnalyticAxes: [],
    budgetIncluded: true,
    sortOrder: 0,
    depth,
    version: 1,
    used: false,
    typeEditable: true,
    typeEditReason: null,
    canAcceptChildren: true,
    archivedAt: null,
    replacement: null,
    ...extra,
  } as Category;
}

const tree = [
  make('a', 'Vie courante', null, 1, { color: '#112233', effectiveColor: '#112233' }),
  make('b', 'Alimentation', 'a', 2, { effectiveColor: '#112233' }),
  make('c', 'Restaurants', 'b', 3, { color: '#AABBCC', effectiveColor: '#AABBCC' }),
  make('d', 'Transports', null, 1),
];

function renderTree(categories: Category[] = tree, handlers = {}) {
  return render(
    <CategoryTree categories={categories} onEdit={vi.fn()} onLifecycle={vi.fn()} {...handlers} />,
  );
}

function toggleOf(label: string) {
  return screen.getByRole('button', { name: new RegExp(`sous-catégories de « ${label} »`) });
}

describe('CategoryTree', () => {
  afterEach(cleanup);

  it('shows roots only and expands each branch independently with its state announced', () => {
    renderTree();

    expect(screen.queryByText('Alimentation')).toBeNull();
    const toggle = toggleOf('Vie courante');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.getAttribute('aria-controls')).toBeTruthy();
    expect(screen.getByText('Alimentation')).toBeTruthy();
    expect(screen.queryByText('Restaurants')).toBeNull();

    fireEvent.click(toggleOf('Alimentation'));
    expect(screen.getByText('Restaurants')).toBeTruthy();

    fireEvent.click(toggle);
    expect(screen.queryByText('Alimentation')).toBeNull();
  });

  it('has no toggle on a leaf and conveys the depth as text', () => {
    renderTree();

    expect(screen.queryByRole('button', { name: /sous-catégories de « Transports »/ })).toBeNull();
    fireEvent.click(toggleOf('Vie courante'));
    fireEvent.click(toggleOf('Alimentation'));
    const leaf = screen.getByText('Restaurants').closest('li') as HTMLElement;
    expect(within(leaf).getByText('Niveau 3')).toBeTruthy();
  });

  it('toggles from the keyboard', () => {
    renderTree();
    const toggle = toggleOf('Vie courante');

    toggle.focus();
    expect(document.activeElement).toBe(toggle);
    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
  });

  it('keeps actions reachable on a collapsed branch', () => {
    const onEdit = vi.fn();
    renderTree(tree, { onEdit });

    fireEvent.click(
      screen.getByRole('button', { name: 'Actions de la catégorie « Vie courante »' }),
    );
    fireEvent.click(screen.getByRole('button', { name: /^Modifier/ }));
    expect(onEdit).toHaveBeenCalledWith(tree[0]);
  });

  it('draws inherited and explicit colors from the effective color', () => {
    renderTree();
    fireEvent.click(toggleOf('Vie courante'));
    fireEvent.click(toggleOf('Alimentation'));

    const fill = (label: string) =>
      (screen.getByText(label).closest('[data-icon]') as HTMLElement).style.background;
    expect(fill('Alimentation')).toBe('rgb(17, 34, 51)');
    expect(fill('Restaurants')).toBe('rgb(170, 187, 204)');
    expect(
      (screen.getByText('Transports').closest('[data-icon]') as HTMLElement).style.background,
    ).toBe('');
  });

  it('draws the glyph inherited from an ancestor', () => {
    renderTree([
      make('p', 'Parent', null, 1, { icon: 'utensils', effectiveIcon: 'utensils' }),
      make('q', 'Enfant', 'p', 2, { effectiveIcon: 'utensils' }),
    ]);
    fireEvent.click(toggleOf('Parent'));

    const pill = screen.getByText('Enfant').closest('[data-icon]') as HTMLElement;
    expect(pill.getAttribute('data-icon')).toBe('utensils');
  });

  it('shows an archived branch under its parent and an orphan as a root with its parent label', () => {
    const archived = make('e', 'Ancienne', 'gone', 2, {
      archivedAt: '2026-09-01T12:00:00+00:00',
      parentLabel: 'Parent archivé',
    });
    renderTree([archived]);

    expect(screen.getByText('Ancienne')).toBeTruthy();
    expect(screen.getByText(/Parent archivé/)).toBeTruthy();
    expect(screen.getByText('Archivée')).toBeTruthy();
  });
});
