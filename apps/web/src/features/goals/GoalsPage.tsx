import {
  closeGoal,
  createGoal,
  listGoals,
  readGoal,
  updateGoal,
  type CreateGoalRequest,
  type GoalDetail,
  type GoalSummary,
  type UpdateGoalRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { useWorkspaceBaseCurrency } from '@/features/auth/useWorkspaceBaseCurrency';
import { CloseGoalDialog } from './CloseGoalDialog';
import { GoalCard } from './GoalCard';
import { GoalForm } from './GoalForm';
import { goalErrorKind, goalRequestError } from './goalError';
import { ReferenceIncomeNote } from './ReferenceIncomeNote';
import styles from './GoalsPage.module.css';

type Editor = GoalSummary | 'create' | null;
function currentYear() {
  return new Date().getFullYear();
}
export function GoalsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [year, setYear] = useState(currentYear());
  const [yearText, setYearText] = useState(String(currentYear()));
  const [state, setState] = useState<'active' | 'closed'>('active');
  const [editor, setEditor] = useState<Editor>(null);
  const [closeTarget, setCloseTarget] = useState<GoalSummary | null>(null);
  const [saved, setSaved] = useState(false);
  const baseCurrency = useWorkspaceBaseCurrency();
  const goals = useQuery({
    queryKey: ['goals', year, state],
    queryFn: async ({ signal }) => {
      const result = await listGoals({ ...authApiOptions(), query: { year, state }, signal });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const detail = useQuery({
    enabled: editor !== null && editor !== 'create',
    queryKey: ['goal', editor && editor !== 'create' ? editor.id : ''],
    queryFn: async ({ signal }) => {
      const result = await readGoal({
        ...authApiOptions(),
        path: { id: (editor as GoalSummary).id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['goals'] });
    void queryClient.invalidateQueries({ queryKey: ['goal'] });
  };
  const save = useMutation({
    mutationFn: async (body: CreateGoalRequest | UpdateGoalRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateGoal({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateGoalRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createGoal({ ...authApiOptions(), body: body as CreateGoalRequest }),
            );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      setEditor(null);
      setSaved(true);
      await refresh();
    },
  });
  const close = useMutation({
    mutationFn: async (goal: GoalSummary) => {
      const result = await withCsrfRetry(() =>
        closeGoal({ ...authApiOptions(), path: { id: goal.id }, body: { version: goal.version } }),
      );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      setCloseTarget(null);
      setSaved(true);
      await refresh();
    },
  });
  const errorKind = goalErrorKind(goals.error, goals.isError);
  const items = goals.data?.items ?? [];
  const editingSummary = editor && editor !== 'create' ? editor : null;
  const latestDetail = detail.data as GoalDetail | undefined;
  const editingGoal: GoalSummary | null =
    editingSummary && latestDetail
      ? {
          ...editingSummary,
          label: latestDetail.label,
          currentVersion: latestDetail.currentVersion,
          version: latestDetail.version,
        }
      : editingSummary;
  return (
    <div className={styles.page}>
      <section className={styles.intro} aria-labelledby="goals-title">
        <div>
          <p>{t('goals.eyebrow')}</p>
          <h2 id="goals-title">{t('goals.title')}</h2>
          <span>{t('goals.description')}</span>
        </div>
        <button
          className="primary-action"
          onClick={() => {
            save.reset();
            setEditor('create');
          }}
          type="button"
        >
          {t('goals.add')}
        </button>
      </section>
      {saved ? <Toast onDismiss={() => setSaved(false)}>{t('goals.saved')}</Toast> : null}
      <section className={`card ${styles.toolbar}`} aria-label={t('goals.filters')}>
        <label>
          <span>{t('goals.year')}</span>
          <input
            inputMode="numeric"
            onChange={(event) => {
              setYearText(event.target.value);
              if (/^\d{4}$/.test(event.target.value)) {
                const next = Number.parseInt(event.target.value, 10);
                if (next >= 1900 && next <= 2999) setYear(next);
              }
            }}
            value={yearText}
          />
        </label>
        <fieldset>
          <legend>{t('goals.state')}</legend>
          <label>
            <input
              checked={state === 'active'}
              name="goal-state"
              onChange={() => setState('active')}
              type="radio"
            />
            {t('goals.active')}
          </label>
          <label>
            <input
              checked={state === 'closed'}
              name="goal-state"
              onChange={() => setState('closed')}
              type="radio"
            />
            {t('goals.closed')}
          </label>
        </fieldset>
      </section>
      {goals.isPending ? (
        <section className={`card ${styles.state}`} aria-busy="true" role="status">
          <h3>{t('goals.loading')}</h3>
        </section>
      ) : goals.isError ? (
        <section className={`card ${styles.state}`} role="alert">
          <h3>{t(errorKind === 'unauthorized' ? 'goals.unauthorized' : 'goals.error')}</h3>
          {errorKind !== 'unauthorized' ? (
            <button className="secondary-action" onClick={() => void goals.refetch()} type="button">
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : items.length === 0 ? (
        <section className={`card ${styles.state}`}>
          <h3>{t(state === 'active' ? 'goals.empty' : 'goals.emptyClosed')}</h3>
          {state === 'active' ? (
            <button className="primary-action" onClick={() => setEditor('create')} type="button">
              {t('goals.add')}
            </button>
          ) : null}
        </section>
      ) : (
        <section className={styles.grid} aria-label={t('goals.list')}>
          <div className={styles.futureSlot}>{t('goals.progressLater')}</div>
          {items.map((goal) => (
            <GoalCard
              goal={goal}
              key={goal.id}
              onClose={setCloseTarget}
              onEdit={(target) => {
                save.reset();
                setEditor(target);
              }}
            />
          ))}
        </section>
      )}
      {editor ? (
        <Modal
          close={() => {
            setEditor(null);
            save.reset();
          }}
          eyebrow={t(editor === 'create' ? 'goals.createEyebrow' : 'goals.editEyebrow')}
          title={t(editor === 'create' ? 'goals.createTitle' : 'goals.editTitle')}
        >
          {editingSummary && !latestDetail && detail.isPending ? (
            <section className={`card ${styles.state}`} aria-busy="true" role="status">
              <h3>{t('goals.loading')}</h3>
            </section>
          ) : (
            <GoalForm
              baseCurrency={baseCurrency}
              goal={editor === 'create' ? undefined : (editingGoal ?? editor)}
              key={
                editor === 'create'
                  ? 'create'
                  : `${editor.id}-${latestDetail?.currentVersion.versionNo ?? editor.currentVersion.versionNo}`
              }
              onSubmit={(body) => save.mutate(body)}
              pending={save.isPending}
              submitError={goalErrorKind(save.error, save.isError)}
            />
          )}
          {detail.data ? <ReferenceIncomeNote goal={detail.data as GoalDetail} /> : null}
        </Modal>
      ) : null}
      {closeTarget ? (
        <Modal
          close={() => {
            setCloseTarget(null);
            close.reset();
          }}
          eyebrow={t('goals.closeEyebrow')}
          title={t('goals.closeTitle')}
        >
          <CloseGoalDialog
            error={goalErrorKind(close.error, close.isError)}
            goal={closeTarget}
            onConfirm={() => close.mutate(closeTarget)}
            pending={close.isPending}
          />
        </Modal>
      ) : null}
    </div>
  );
}
