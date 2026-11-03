import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MovementDetails } from './MovementDetails';

const api = vi.hoisted(() => ({ readMovements: vi.fn(), readPreferences: vi.fn() }));
vi.mock('@cadran/api-client', () => ({
  readInterfacePreferences: api.readPreferences,
  readMonthlyLedgerMovements: api.readMovements,
}));
beforeEach(() => {
  api.readMovements.mockReset().mockImplementation(() => new Promise(() => undefined));
  api.readPreferences.mockReset().mockResolvedValue({
    data: { includeUpcoming: true, version: 0 },
    response: new Response('{}', { status: 200 }),
  });
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
        expect(container.parentElement?.querySelector('[data-visual-mask]')).toBeTruthy();
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
      amount: { value: '-12.34', assetCode: 'EUR' },
      direction: null,
      counterpartAccountId: null,
      counterpartAccountLabel: null,
      pending: false,
      upcoming: false,
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
    expect(longLabelNode.closest('li')?.querySelector('[data-visual-mask]')).toBeTruthy();
    expect(longLabelNode.closest('li')?.parentElement?.previousElementSibling?.tagName).toBe(
      'TIME',
    );
    expect(
      screen.getByRole('button', {
        name: `Modifier le mouvement ${longLabel} du 20 octobre 2026, -12,34 €`,
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

  it('explains a refund beside its movement on demand', async () => {
    api.readMovements.mockResolvedValue({
      data: {
        items: [
          {
            id: 'refund',
            transactionId: 'tx-refund',
            transferId: null,
            bookedOn: '2026-11-02',
            label: 'Remboursement médecin',
            amount: { value: '18.00', assetCode: 'EUR' },
            direction: null,
            counterpartAccountId: null,
            counterpartAccountLabel: null,
            pending: false,
            upcoming: false,
          },
        ],
        hasMore: false,
        nextCursor: null,
      },
      response: new Response('{}', { status: 200 }),
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    render(
      <QueryClientProvider client={client}>
        <MovementDetails
          axis={null}
          editable
          kind="expense"
          language="fr"
          month="2026-11"
          rowId="row-1"
          onEditTransaction={vi.fn()}
        />
      </QueryClientProvider>,
    );
    const info = await screen.findByRole('button', { name: 'Comprendre ce remboursement' });
    expect(info.nextElementSibling?.hasAttribute('data-visual-mask')).toBe(true);
    expect(screen.queryByText(/réduit les dépenses du mois/)).toBeNull();
    fireEvent.click(info);
    expect(screen.getByRole('dialog', { name: 'Remboursement dans le budget' })).toBeTruthy();
    expect(screen.getByText(/réduit les dépenses du mois/)).toBeTruthy();
    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it.each(['expense', 'income', 'account'] as const)(
    'greys a pending %s movement with a visible badge and keeps booked rows unchanged',
    async (kind) => {
      const row = (id: string, pending: boolean) => ({
        id,
        transactionId: `tx-${id}`,
        transferId: null,
        bookedOn: '2026-10-20',
        label: `Libellé ${id}`,
        amount: { value: '-50.00', assetCode: 'EUR' },
        direction: null,
        counterpartAccountId: null,
        counterpartAccountLabel: null,
        pending,
      });
      api.readMovements.mockResolvedValueOnce({
        data: {
          items: [row('booked', false), row('waiting', true)],
          hasMore: false,
          nextCursor: null,
        },
        response: new Response('{}', { status: 200 }),
      });
      const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
      render(
        <QueryClientProvider client={client}>
          <MovementDetails
            axis={null}
            editable
            kind={kind}
            language="fr"
            month="2026-10"
            rowId="row-1"
            onEditTransaction={vi.fn()}
          />
        </QueryClientProvider>,
      );

      const waiting = (await screen.findByText('Libellé waiting')).closest('li')!;
      const booked = screen.getByText('Libellé booked').closest('li')!;
      expect(waiting.getAttribute('data-pending')).toBe('true');
      expect(within(waiting).getByText('(en attente)')).toBeTruthy();
      expect(waiting.querySelector('[data-visual-mask]')?.textContent).toMatch(/50,00/);
      expect(waiting.firstElementChild?.className).toContain('pending');
      expect(booked.hasAttribute('data-pending')).toBe(false);
      expect(within(booked).queryByText('(en attente)')).toBeNull();
      expect(booked.firstElementChild?.className).not.toContain('pending');
    },
  );

  it('marks a planned movement "(à venir)" and asks for upcoming rows with the setting', async () => {
    api.readMovements.mockResolvedValueOnce({
      data: {
        items: [
          {
            id: 'u',
            transactionId: 'tx-u',
            transferId: null,
            bookedOn: '2026-11-20',
            label: 'Internet',
            amount: { value: '-29.99', assetCode: 'EUR' },
            direction: null,
            counterpartAccountId: null,
            counterpartAccountLabel: null,
            pending: false,
            upcoming: true,
          },
        ],
        hasMore: false,
        nextCursor: null,
      },
      response: new Response('{}', { status: 200 }),
    });
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    render(
      <QueryClientProvider client={client}>
        <MovementDetails
          axis={null}
          editable
          kind="expense"
          language="fr"
          month="2026-11"
          rowId="row-1"
          onEditTransaction={vi.fn()}
        />
      </QueryClientProvider>,
    );

    expect(await screen.findByText('(à venir)')).toBeTruthy();
    expect(api.readMovements).toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ includeUpcoming: true }) }),
    );
  });
});
