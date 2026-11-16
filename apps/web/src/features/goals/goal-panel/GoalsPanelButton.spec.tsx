import type { GoalEvaluations } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { countGoals, GoalsPanelButton } from './GoalsPanelButton';
import type { GoalPanelScope } from './useGoalPanelData';

const api = vi.hoisted(() => ({ listGoalEvaluations: vi.fn(), listGoals: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T) =>
  Promise.resolve({ data, response: new Response('{}', { status: 200 }) });
const item = (status: string, periodicity = 'MONTHLY', gap: string | null = null) =>
  ({ goalId: status + periodicity + gap, label: 'x', periodicity, status, gap }) as never;
const evaluations = (...items: unknown[]) =>
  ({
    months: [{ period: '2026-11', items: items }],
    years: [{ period: '2026', items: [item('MET', 'YEARLY')] }],
  }) as unknown as GoalEvaluations;

function renderButton(
  onOpen = vi.fn(),
  scope: GoalPanelScope = { kind: 'month', month: '2026-11' },
  ready = true,
) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <GoalsPanelButton buttonRef={{ current: null }} onOpen={onOpen} ready={ready} scope={scope} />
    </QueryClientProvider>,
  );
  return onOpen;
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('countGoals', () => {
  it('includes running goals within the server tolerance in the header count', () => {
    const data = evaluations(
      { status: 'IN_PROGRESS', periodicity: 'MONTHLY', gap: '-40', withinTolerance: true },
      { status: 'IN_PROGRESS', periodicity: 'MONTHLY', gap: '-60', withinTolerance: false },
    );
    expect(countGoals(data, { kind: 'month', month: '2026-11' })).toEqual({ met: 1, total: 2 });
  });
  it('counts the goals on target now: running ones with a favourable gap too', () => {
    const data = evaluations(
      item('IN_PROGRESS', 'MONTHLY', '0.00'),
      item('IN_PROGRESS', 'MONTHLY', '40.00'),
      item('IN_PROGRESS', 'MONTHLY', '-60.00'),
    );
    expect(countGoals(data, { kind: 'month', month: '2026-11' })).toEqual({ met: 2, total: 3 });
  });

  it('adds up the yearly goals on target over the selected years', () => {
    const data = {
      months: [],
      years: [
        { period: '2024', items: [item('MET', 'YEARLY'), item('MISSED', 'YEARLY')] },
        { period: '2025', items: [] },
        { period: '2026', items: [item('IN_PROGRESS', 'YEARLY', '0.00')] },
        { period: '2027', items: [item('MET', 'YEARLY')] },
      ],
    } as unknown as GoalEvaluations;
    expect(countGoals(data, { kind: 'years', years: [2024, 2025, 2026] })).toEqual({
      met: 2,
      total: 3,
    });
  });

  it('counts the met goals of the scope and counts not calculable ones in the total only', () => {
    const data = evaluations(item('MET'), item('MISSED'), item('NOT_CALCULABLE'), item('MET'));
    expect(countGoals(data, { kind: 'month', month: '2026-11' })).toEqual({ met: 2, total: 4 });
    expect(countGoals(data, { kind: 'year', year: 2026 })).toEqual({ met: 1, total: 1 });
    expect(countGoals(data, { kind: 'month', month: '2026-10' })).toEqual({ met: 0, total: 0 });
  });
});

describe('GoalsPanelButton', () => {
  it('shows a disabled shell with skeleton blocks while loading', () => {
    api.listGoals.mockImplementation(() => new Promise(() => undefined));
    api.listGoalEvaluations.mockImplementation(() => new Promise(() => undefined));
    renderButton();
    const button = screen.getByRole('button', { name: 'Objectifs' });
    expect(button.hasAttribute('disabled')).toBe(true);
    expect(button.querySelectorAll('[data-skeleton]')).toHaveLength(2);
  });

  it('names the progress, hides the bar from assistive technology, and opens the panel', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(() =>
      ok(evaluations(item('MET'), item('IN_PROGRESS'))),
    );
    const onOpen = renderButton();
    const button = await screen.findByRole('button', { name: 'Objectifs : 1 sur 2 atteints' });
    expect(button.querySelector('[aria-hidden="true"]')).toBeTruthy();
    expect(button.textContent).toContain('1 / 2');
    fireEvent.click(button);
    expect(onOpen).toHaveBeenCalledOnce();
  });

  it('fills the bar when every goal is met', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(() => ok(evaluations(item('MET'), item('MET'))));
    renderButton();
    const button = await screen.findByRole('button', { name: 'Objectifs : 2 sur 2 atteints' });
    const fill = button.querySelector<HTMLElement>('span[style]')!;
    expect(fill.style.width).toBe('100%');
  });

  it.each([
    ['no goal', () => ok(evaluations())],
    ['an error', () => Promise.reject(new TypeError('offline'))],
  ])('falls back to the plain button without a bar or 0 / 0 on %s', async (_name, response) => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(response);
    renderButton();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Objectifs' }).hasAttribute('disabled')).toBe(
        false,
      ),
    );
    const button = screen.getByRole('button', { name: 'Objectifs' });
    expect(button.textContent).toBe('');
    expect(button.querySelector('[aria-hidden="true"]:not(svg)')).toBeNull();
  });

  it('asks for nothing before the page is ready, and never for the goals list', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(() => ok(evaluations(item('MET'))));
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    const view = (ready: boolean) => (
      <QueryClientProvider client={client}>
        <GoalsPanelButton
          buttonRef={{ current: null }}
          onOpen={vi.fn()}
          ready={ready}
          scope={{ kind: 'month', month: '2026-11' }}
        />
      </QueryClientProvider>
    );
    const { rerender } = render(view(false));
    await new Promise((resolve) => setTimeout(resolve, 30));
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Objectifs' }).hasAttribute('disabled')).toBe(true);

    rerender(view(true));
    expect(
      await screen.findByRole('button', { name: 'Objectifs : 1 sur 1 atteints' }),
    ).toBeTruthy();
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(1);
    expect(api.listGoals).not.toHaveBeenCalled();
  });

  it('requests the selected years one after the other, never two at once', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    let inFlight = 0;
    let peak = 0;
    api.listGoalEvaluations.mockImplementation(async ({ query }: { query: { from: string } }) => {
      inFlight += 1;
      peak = Math.max(peak, inFlight);
      await new Promise((resolve) => setTimeout(resolve, 10));
      inFlight -= 1;
      return {
        data: {
          months: [],
          years: [{ period: query.from.slice(0, 4), items: [item('MET', 'YEARLY')] }],
        },
        response: new Response('{}', { status: 200 }),
      };
    });
    renderButton(vi.fn(), { kind: 'years', years: [2024, 2025, 2026] });

    expect(
      await screen.findByRole('button', { name: 'Objectifs : 3 sur 3 atteints' }),
    ).toBeTruthy();
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(3);
    expect(peak).toBe(1);
  });

  it('continues the chain when a year fails, and reuses a fresh answer when remounted', async () => {
    api.listGoals.mockImplementation(() => ok({ items: [] }));
    api.listGoalEvaluations.mockImplementation(({ query }: { query: { from: string } }) =>
      query.from.startsWith('2024') ? Promise.reject(new TypeError('offline')) : ok(evaluations()),
    );
    renderButton(vi.fn(), { kind: 'years', years: [2024, 2025] });
    await waitFor(() => expect(api.listGoalEvaluations).toHaveBeenCalledTimes(2));
  });
});
