import type { Category } from '@cadran/api-client';
import { describe, expect, it } from 'vitest';
import { buildCategoryTree } from './buildCategoryTree';

function make(id: string, parentId: string | null, label = id): Category {
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
    depth: 1,
    version: 1,
    used: false,
    typeEditable: true,
    typeEditReason: null,
    canAcceptChildren: true,
    archivedAt: null,
    replacement: null,
  };
}

describe('buildCategoryTree', () => {
  it('nests children under their parent at any depth and keeps the server order', () => {
    const tree = buildCategoryTree([
      make('a', null),
      make('b', 'a'),
      make('c', 'b'),
      make('d', 'a'),
      make('e', null),
    ]);

    expect(tree.map((node) => node.category.id)).toEqual(['a', 'e']);
    expect(tree[0]?.children.map((node) => node.category.id)).toEqual(['b', 'd']);
    expect(tree[0]?.children[0]?.children.map((node) => node.category.id)).toEqual(['c']);
  });

  it('promotes a category whose parent is absent to a root', () => {
    const tree = buildCategoryTree([make('b', 'archived-parent'), make('c', 'b')]);

    expect(tree.map((node) => node.category.id)).toEqual(['b']);
    expect(tree[0]?.children.map((node) => node.category.id)).toEqual(['c']);
  });

  it('does not loop on a cyclic payload', () => {
    expect(buildCategoryTree([make('a', 'b'), make('b', 'a')])).toEqual([]);
  });
});
