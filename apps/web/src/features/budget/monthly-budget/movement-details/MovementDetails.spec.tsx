import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MovementDetails } from './MovementDetails';

const api = vi.hoisted(() => ({ readMovements: vi.fn() }));
vi.mock('@cadran/api-client', () => ({ readMonthlyLedgerMovements: api.readMovements }));
beforeEach(() => {
  api.readMovements.mockReset().mockImplementation(() => new Promise(() => undefined));
});

afterEach(cleanup);

describe('MovementDetails loading structure', () => {
  it.each([
    { editable: true, kind: 'income' as const, onEdit: true, tag: 'BUTTON' },
    { editable: true, kind: 'expense' as const, onEdit: true, tag: 'BUTTON' },
    { editable: false, kind: 'expense' as const, onEdit: true, tag: 'DIV' },
    { editable: true, kind: 'account' as const, onEdit: true, tag: 'DIV' },
    { editable: true, kind: 'income' as const, onEdit: false, tag: 'DIV' },
  ])(
    'uses $tag for pending $kind movements with editable=$editable and editor=$onEdit',
    ({ editable, kind, onEdit, tag }) => {
      const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
      render(
        <QueryClientProvider client={client}>
          <MovementDetails
            axis={null}
            editable={editable}
            kind={kind}
            language="fr"
            month="2026-10"
            rowId="row-1"
            onEditTransaction={onEdit ? vi.fn() : undefined}
          />
        </QueryClientProvider>,
      );
      const list = screen.getAllByRole('list')[0]!;
      expect(list.getAttribute('aria-busy')).toBe('true');
      const rows = [...list.querySelectorAll(':scope > li')];
      expect(rows).toHaveLength(3);
      for (const row of rows) {
        expect(row.querySelector(':scope > time > [data-skeleton]')).toBeTruthy();
        const container = row.querySelector('ul > li')!.firstElementChild!;
        expect(container.tagName).toBe(tag);
        expect(container.className).toContain('movement');
        expect(container.querySelector('strong > [data-skeleton]')).toBeTruthy();
        expect(container.querySelector('[data-visual-mask]')).toBeTruthy();
        if (tag === 'BUTTON') {
          expect(container.className).toContain('movementAction');
          expect(container.hasAttribute('disabled')).toBe(true);
          expect(container.getAttribute('type')).toBe('button');
        } else {
          expect(container.className).not.toContain('movementAction');
        }
      }
    },
  );
  it('groups a date across pages without changing movement order and preserves complete labels', async () => {
    const longLabel =
      'Une transaction au libellé suffisamment long pour être tronqué visuellement sur mobile';
    const movement = (id: string, date: string, label: string) => ({
      id,
      transactionId: `tx-${id}`,
      transferId: null,
      bookedOn: date,
      label,
      amount: { value: '12.34', assetCode: 'EUR' },
      direction: null,
      counterpartAccountId: null,
      counterpartAccountLabel: null,
    });
    const result = (items: ReturnType<typeof movement>[], hasMore: boolean) => ({
      data: { items, hasMore, nextCursor: hasMore ? 'next' : null },
      response: new Response('{}', { status: 200 }),
    });
    api.readMovements
      .mockResolvedValueOnce(
        result(
          [movement('a', '2026-10-20', longLabel), movement('b', '2026-10-20', 'Deuxième')],
          true,
        ),
      )
      .mockResolvedValueOnce(
        result(
          [movement('c', '2026-10-20', 'Troisième'), movement('d', '2026-10-19', 'Quatrième')],
          false,
        ),
      );
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    render(
      <QueryClientProvider client={client}>
        <MovementDetails
          axis={null}
          editable
          kind="expense"
          language="fr"
          month="2026-10"
          rowId="row-1"
          onEditTransaction={vi.fn()}
        />
      </QueryClientProvider>,
    );
    expect(await screen.findByText('20 octobre')).toBeTruthy();
    expect(screen.getAllByText('20 octobre')).toHaveLength(1);
    const longLabelNode = screen.getByText(longLabel);
    expect(longLabelNode.getAttribute('title')).toBe(longLabel);
    expect(longLabelNode.parentElement?.querySelector('[data-visual-mask]')).toBeTruthy();
    expect(longLabelNode.closest('li')?.parentElement?.previousElementSibling?.tagName).toBe(
      'TIME',
    );
    expect(
      screen.getByRole('button', {
        name: `Modifier le mouvement ${longLabel} du 20 octobre 2026, 12,34 €`,
      }),
    ).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Afficher les mouvements suivants' }));
    expect(await screen.findByText('Quatrième')).toBeTruthy();
    expect(screen.getAllByText('20 octobre')).toHaveLength(1);
    expect(screen.getByText('19 octobre').getAttribute('datetime')).toBe('2026-10-19');
    const list = screen.getAllByRole('list')[0]!;
    expect([...list.querySelectorAll('strong')].map((node) => node.textContent)).toEqual([
      longLabel,
      'Deuxième',
      'Troisième',
      'Quatrième',
    ]);
    expect(within(list).getAllByRole('button')).toHaveLength(4);
    expect(api.readMovements.mock.calls[1]?.[0].query.cursor).toBe('next');
  });
});
