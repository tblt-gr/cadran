import type { AccountGroup } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupTree } from './GroupTree';

function make(
  id: string,
  label: string,
  parentId: string | null,
  depth: number,
  extra: Partial<AccountGroup> = {},
): AccountGroup {
  return {
    color: null,
    colorMode: 'AUTO',
    resolvedColor: '#486DF0',
    defaultColor: '#486DF0',
    parentResolvedColor: null,
    id,
    label,
    parentId,
    parentLabel: null,
    sortOrder: 0,
    depth,
    version: 1,
    hasChildren: false,
    canAcceptChildren: true,
    share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
    archivedAt: null,
    ...extra,
  };
}

const groups = [
  make('a', 'Épargne', null, 1, { hasChildren: true }),
  make('b', 'Livrets', 'a', 2, { hasChildren: true, resolvedColor: '#7B96F4' }),
  make('c', 'Livret A', 'b', 3),
  make('d', 'Courant', null, 1),
];

function renderTree(items: AccountGroup[] = groups, handlers = {}) {
  return render(<GroupTree groups={items} onArchive={vi.fn()} onEdit={vi.fn()} {...handlers} />);
}

function toggleOf(label: string) {
  return screen.getByRole('button', { name: new RegExp(`sous-groupes de « ${label} »`) });
}

describe('GroupTree', () => {
  afterEach(cleanup);

  it('shows roots only and expands each branch independently with its state announced', () => {
    renderTree();

    expect(screen.queryByText('Livrets')).toBeNull();
    const toggle = toggleOf('Épargne');
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
    expect(toggle.getAttribute('aria-label')).toContain('(1)');

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.getAttribute('aria-controls')).toBeTruthy();
    expect(screen.getByText('Livrets')).toBeTruthy();
    expect(screen.queryByText('Livret A')).toBeNull();

    fireEvent.click(toggleOf('Livrets'));
    expect(screen.getByText('Livret A')).toBeTruthy();

    fireEvent.click(toggle);
    expect(screen.queryByText('Livrets')).toBeNull();
    expect(toggle.getAttribute('aria-expanded')).toBe('false');
  });

  it('exposes the toggle to the keyboard as a native button', () => {
    renderTree();

    expect(toggleOf('Épargne').tagName).toBe('BUTTON');
    expect(screen.queryByRole('button', { name: /sous-groupes de « Courant »/ })).toBeNull();
  });

  it('states the number of direct sub-groups and the level in text', () => {
    renderTree();

    expect(screen.getByText('1 sous-groupe')).toBeTruthy();
    fireEvent.click(toggleOf('Épargne'));
    expect(screen.getByText('Niveau 2')).toBeTruthy();
  });

  it('keeps the action menu reachable without expanding', () => {
    const onEdit = vi.fn();
    renderTree(groups, { onEdit });

    fireEvent.click(screen.getByRole('button', { name: 'Actions du groupe Épargne' }));
    fireEvent.click(screen.getByRole('button', { name: 'Modifier le groupe Épargne' }));
    expect(onEdit).toHaveBeenCalledWith(groups[0]);
  });

  it('paints the marker with the effective color and has no table columns', () => {
    renderTree();
    fireEvent.click(toggleOf('Épargne'));

    const marker = screen.getByText('Livrets').closest('li')?.querySelector('[data-marker]');
    expect((marker as HTMLElement).style.backgroundColor).toBe('rgb(123, 150, 244)');
    expect(screen.queryByRole('columnheader')).toBeNull();
    expect(screen.queryByText('Actif')).toBeNull();
  });

  it('marks archived groups with text and disables their edition', () => {
    const archived = make('e', 'Ancien', null, 1, { archivedAt: '2026-09-01T12:00:00+00:00' });
    renderTree([archived]);

    expect(screen.getByText('Archivé')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Actions du groupe Ancien' }));
    expect(screen.getByRole('button', { name: /Modifier/ }).hasAttribute('disabled')).toBe(true);
  });

  it('promotes a group with a hidden parent to a root', () => {
    renderTree([make('b', 'Livrets', 'archived-parent', 2)]);

    expect(screen.getByText('Livrets')).toBeTruthy();
  });
});
