import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import type { GoalDetail, GoalMetric, GoalSchedule, GoalValueType } from '@cadran/api-client';
import '@/i18n';
import { goalEvaluation } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { GoalDetailModal } from './GoalDetailModal';

const api = vi.hoisted(() => ({ readGoal: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.resetAllMocks();
});
const version = {
  versionNo: 1,
  effectiveFrom: '2026-01',
  valueType: 'AMOUNT' as const,
  targetAmount: '100',
  ratio: null,
  tolerance: '5',
  schedule: null,
};
const detail: GoalDetail = {
  id: 'goal',
  label: 'Essentiel',
  periodicity: 'MONTHLY',
  startPeriod: '2026-01',
  endPeriod: null,
  metric: 'TOTAL_SPENDING',
  direction: 'CEILING',
  scope: { type: 'WORKSPACE', label: 'Espace', archived: false },
  assetCode: 'EUR',
  currentVersion: version,
  version: 1,
  warnings: [],
  status: null,
  versions: [version],
};
function mount(evaluation = goalEvaluation()) {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <GoalDetailModal
        goalId="goal"
        label="Essentiel"
        period="2026-04"
        evaluation={evaluation}
        close={vi.fn()}
      />
    </QueryClientProvider>,
  );
}
const ok = (value: GoalDetail) => ({ data: value, response: new Response(null, { status: 200 }) });

it('shows chronological recorded values and identifies the evaluated revision, including tolerance', async () => {
  api.readGoal.mockResolvedValue(
    ok({
      ...detail,
      versions: [
        { ...version, versionNo: 2, effectiveFrom: '2026-06', targetAmount: '200' },
        version,
      ],
    }),
  );
  mount(goalEvaluation({ versionNo: 1, status: 'MET', withinTolerance: true, gap: '-5' }));
  await waitFor(() =>
    expect(screen.queryByText('Chargement des détails de l’objectif…')).toBeNull(),
  );
  expect(screen.getByText('Atteint grâce à la tolérance.')).toBeTruthy();
  expect(screen.getByText('Version appliquée à cette période')).toBeTruthy();
  const versions = screen.getAllByRole('heading', { level: 4, name: /^Version / });
  expect(versions.map((heading) => heading.textContent)).toEqual(['Version 1', 'Version 2']);
  expect(screen.getByText(/100,00/)).toBeTruthy();
  expect(api.readGoal.mock.calls[0]![0].path).toEqual({ id: 'goal' });
  expect(screen.getAllByRole('heading', { level: 3 })[0]?.textContent).toBe(
    'Avancement : Avril 2026',
  );
  expect(screen.getByRole('meter').getAttribute('aria-valuenow')).toBe('1');
  expect(screen.getByRole('heading', { name: 'Comprendre le résultat' })).toBeTruthy();
});
it('keeps known headings during loading and handles an empty history', async () => {
  let resolve!: (value: ReturnType<typeof ok>) => void;
  api.readGoal.mockReturnValue(
    new Promise((done) => {
      resolve = done;
    }),
  );
  mount();
  expect(screen.getByRole('heading', { name: 'Identité et période' })).toBeTruthy();
  expect(screen.getByRole('heading', { name: 'Historique des versions' })).toBeTruthy();
  expect(document.querySelector('[data-skeleton]')).toBeTruthy();
  const loadingAccordion = document.querySelector('details')!;
  expect(loadingAccordion.open).toBe(true);
  expect(loadingAccordion.querySelector('summary')?.getAttribute('aria-disabled')).toBe('true');
  resolve(ok({ ...detail, versions: [] }));
  expect(await screen.findByText('Aucune version n’est disponible.')).toBeTruthy();
});

it('opens the applied revision by default and expands other revisions independently', async () => {
  api.readGoal.mockResolvedValue(
    ok({
      ...detail,
      versions: [
        version,
        { ...version, versionNo: 2, effectiveFrom: '2026-06', targetAmount: '200' },
      ],
    }),
  );
  mount();
  await screen.findByText('Version appliquée à cette période');
  const applied = screen.getByRole('heading', { name: 'Version 1' }).closest('details')!;
  const other = screen.getByRole('heading', { name: 'Version 2' }).closest('details')!;
  expect(applied.open).toBe(true);
  expect(other.open).toBe(false);
  fireEvent.click(other.querySelector('summary')!);
  await waitFor(() => expect(other.open).toBe(true));
  expect(applied.open).toBe(true);
  fireEvent.click(applied.querySelector('summary')!);
  await waitFor(() => expect(applied.open).toBe(false));
  expect(applied.querySelector('summary')?.textContent).toContain(
    'Version appliquée à cette période',
  );
});
it('offers retry on a failed read', async () => {
  api.readGoal.mockRejectedValueOnce(new Error('network')).mockResolvedValueOnce(ok(detail));
  mount();
  expect(await screen.findByRole('alert')).toBeTruthy();
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  expect(await screen.findByText('Version appliquée à cette période')).toBeTruthy();
});
it('explains non-calculable evaluation without manufacturing a zero', async () => {
  api.readGoal.mockResolvedValue(ok(detail));
  mount(
    goalEvaluation({
      achieved: null,
      target: null,
      gap: null,
      status: 'NOT_CALCULABLE',
      reason: 'NO_DATA',
    }),
  );
  expect(await screen.findByText('Aucune donnée pour cette période')).toBeTruthy();
  expect(screen.getByText('Écart non calculable pour cette période.')).toBeTruthy();
  expect(screen.queryByRole('meter')).toBeNull();
});

it('shows every server schedule part and cumulative value for a recorded revision', async () => {
  const parts = Array.from({ length: 12 }, (_, index) => ({
    month: `2026-${String(index + 1).padStart(2, '0')}`,
    amount: '10',
    cumulative: String((index + 1) * 10),
  })) as GoalSchedule['parts'];
  api.readGoal.mockResolvedValue(
    ok({
      ...detail,
      periodicity: 'YEARLY',
      startPeriod: '2026',
      versions: [{ ...version, targetAmount: '120', schedule: { mode: 'CUSTOM', parts } }],
    }),
  );
  mount();
  expect(await screen.findByText('Personnalisé')).toBeTruthy();
  expect(screen.getAllByRole('row')).toHaveLength(13);
  expect(screen.getByRole('row', { name: /2026-12/ }).textContent).toMatch(/120,00/);
});

it('orders retroactive revisions by effective period with version number as a tie-break', async () => {
  api.readGoal.mockResolvedValue(
    ok({
      ...detail,
      versions: [
        { ...version, versionNo: 2, effectiveFrom: '2026-06' },
        { ...version, versionNo: 3, effectiveFrom: '2026-01' },
        version,
      ],
    }),
  );
  mount();
  await screen.findByText('Version appliquée à cette période');
  expect(
    screen
      .getAllByRole('heading', { level: 4, name: /^Version / })
      .map((heading) => heading.textContent),
  ).toEqual(['Version 1', 'Version 3', 'Version 2']);
});

it.each<[GoalMetric, GoalValueType]>([
  ['NET_WORTH', 'AMOUNT'],
  ['ACCOUNT_BALANCE', 'AMOUNT'],
  ['SAVINGS_RATE', 'RATE'],
  ['NET_SAVINGS_RATE', 'RATE'],
  ['TOTAL_SPENDING', 'INCOME_SHARE'],
])(
  'omits the calendar for annual %s goals during loading and after the read',
  async (metric, valueType) => {
    let resolve!: (value: ReturnType<typeof ok>) => void;
    api.readGoal.mockReturnValue(
      new Promise((done) => {
        resolve = done;
      }),
    );
    mount(goalEvaluation({ periodicity: 'YEARLY', metric, valueType }));
    expect(screen.queryByRole('table', { name: 'Calendrier mensuel de l’objectif' })).toBeNull();
    resolve(
      ok({
        ...detail,
        metric,
        periodicity: 'YEARLY',
        startPeriod: '2026',
        versions: [
          {
            ...version,
            valueType,
            targetAmount: valueType === 'AMOUNT' ? '100' : null,
            ratio: valueType === 'AMOUNT' ? null : '0.2',
          },
        ],
      }),
    );
    await screen.findByText('Version appliquée à cette période');
    expect(screen.queryByRole('heading', { name: 'Calendrier mensuel' })).toBeNull();
    expect(screen.queryByRole('table', { name: 'Calendrier mensuel de l’objectif' })).toBeNull();
  },
);
