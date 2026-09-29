import {
  closeGoal,
  createGoal,
  listGoals,
  readGoal,
  readGoalStatus,
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
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { CloseGoalDialog } from './CloseGoalDialog';
import { GoalCard } from './GoalCard';
import { GoalDetailModal } from './GoalDetailModal';
import { GoalFilters, type GoalListState } from './GoalFilters';
import { GoalForm } from './GoalForm';
import { GoalScheduleTable } from './goal-schedule-table/GoalScheduleTable';
import { goalErrorKind, goalErrorMismatch, goalRequestError } from './goalError';
import { ReferenceIncomeNote } from './ReferenceIncomeNote';
import styles from './GoalsPage.module.css';
import { currentMonthIn } from './goalFormFields';

type Editor = GoalSummary | 'create' | null;
function currentYear(timeZone: string) {
  return Number.parseInt(currentMonthIn(timeZone).slice(0, 4), 10);
}
export function GoalsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const timeZone = useWorkspaceTimeZone();
  const workspaceMonth = currentMonthIn(timeZone);
  const [year, setYear] = useState(() => currentYear(timeZone));
  const [state, setState] = useState<GoalListState>('active');
  const [month, setMonth] = useState(workspaceMonth);
  const [detailTarget, setDetailTarget] = useState<GoalSummary | null>(null);
  const [editor, setEditor] = useState<Editor>(null);
  const [closeTarget, setCloseTarget] = useState<GoalSummary | null>(null);
  const [saved, setSaved] = useState(false);
  const baseCurrency = useWorkspaceBaseCurrency();
  // A future year has no elapsed month to send: the server then falls back to its own reference
  // month rather than rejecting the whole list, and no month of it can be detailed yet.
  const futureYear = year > currentYear(timeZone);
  const listMonth = futureYear ? undefined : month;
  const goals = useQuery({
    queryKey: ['goals', year, listMonth, state],
    queryFn: async ({ signal }) => {
      const result = await listGoals({
        ...authApiOptions(),
        query: { year, month: listMonth, state },
        signal,
      });
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
  const statusDetail = useQuery({
    enabled: detailTarget !== null && !futureYear,
    queryKey: ['goal-status', detailTarget?.id, month],
    queryFn: async ({ signal }) => {
      const result = await readGoalStatus({
        ...authApiOptions(),
        path: { id: detailTarget!.id },
        query: { month },
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
      <GoalFilters
        currentMonth={workspaceMonth}
        month={month}
        onMonthChange={setMonth}
        onStateChange={setState}
        onYearChange={(nextYear) => {
          setYear(nextYear);
          setMonth(
            nextYear === currentYear(timeZone)
              ? workspaceMonth
              : nextYear < currentYear(timeZone)
                ? `${nextYear}-12`
                : `${nextYear}-01`,
          );
        }}
        state={state}
        year={year}
      />
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
            <button
              className="secondary-action"
              onClick={() => {
                save.reset();
                setEditor('create');
              }}
              type="button"
            >
              {t('goals.add')}
            </button>
          ) : null}
        </section>
      ) : (
        <section className={styles.list} aria-label={t('goals.list')}>
          <div className={styles.grid}>
            {items.map((goal) => (
              <GoalCard
                goal={goal}
                key={goal.id}
                onClose={setCloseTarget}
                onDetail={futureYear ? undefined : setDetailTarget}
                onEdit={(target) => {
                  save.reset();
                  setEditor(target);
                }}
              />
            ))}
          </div>
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
          {detail.data ? <ReferenceIncomeNote goal={detail.data as GoalDetail} /> : null}
          {latestDetail?.kind === 'ANNUAL' ? (
            <GoalScheduleTable
              assetCode={latestDetail.assetCode}
              schedule={latestDetail.currentVersion.schedule}
            />
          ) : null}
          {editingSummary && !latestDetail && detail.isPending ? (
            <p className={styles.modalLoading} aria-busy="true" role="status">
              {t('goals.loading')}
            </p>
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
              submitMismatch={goalErrorMismatch(save.error)}
            />
          )}
        </Modal>
      ) : null}
      {detailTarget ? (
        statusDetail.isPending ? (
          <Modal
            close={() => setDetailTarget(null)}
            eyebrow={t('goals.detailEyebrow')}
            title={t('goals.detailTitle')}
          >
            <p className={styles.modalLoading} aria-busy="true" role="status">
              {t('goals.loading')}
            </p>
          </Modal>
        ) : statusDetail.data ? (
          <GoalDetailModal
            assetCode={detailTarget.assetCode}
            close={() => setDetailTarget(null)}
            status={statusDetail.data}
          />
        ) : (
          <Modal
            close={() => setDetailTarget(null)}
            eyebrow={t('goals.detailEyebrow')}
            title={t('goals.detailTitle')}
          >
            <p role="alert">{t('goals.error')}</p>
          </Modal>
        )
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
