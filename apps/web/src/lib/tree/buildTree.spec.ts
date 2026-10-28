import { describe, expect, it } from 'vitest';
import { buildTree } from './buildTree';

function make(id: string, parentId: string | null) {
  return { id, parentId };
}

describe('buildTree', () => {
  it('nests children under their parent at any depth and keeps the input order', () => {
    const tree = buildTree([
      make('a', null),
      make('b', 'a'),
      make('c', 'b'),
      make('d', 'a'),
      make('e', null),
    ]);

    expect(tree.map((node) => node.item.id)).toEqual(['a', 'e']);
    expect(tree[0]?.children.map((node) => node.item.id)).toEqual(['b', 'd']);
    expect(tree[0]?.children[0]?.children.map((node) => node.item.id)).toEqual(['c']);
  });

  it('promotes an item whose parent is absent to a root', () => {
    const tree = buildTree([make('b', 'archived-parent'), make('c', 'b')]);

    expect(tree.map((node) => node.item.id)).toEqual(['b']);
    expect(tree[0]?.children.map((node) => node.item.id)).toEqual(['c']);
  });

  it('does not loop on a cyclic payload', () => {
    expect(buildTree([make('a', 'b'), make('b', 'a')])).toEqual([]);
  });
});
