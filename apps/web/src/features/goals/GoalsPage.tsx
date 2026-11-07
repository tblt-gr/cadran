import {
  createGoal,
  listGoals,
  readGoal,
  readGoalStatus,
  listGoalVersions,
  previewGoalCorrection,
  updateGoal,
  type CreateGoalRequest,
  type GoalDetail,
  type GoalCorrectionPreview,
  type GoalPeriodicity,
  type GoalSummary,
  type UpdateGoalRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSubmissionKey } from '@/hooks/use-submission-key';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { useWorkspaceBaseCurrency } from '@/features/auth/useWorkspaceBaseCurrency';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { GoalCard } from './GoalCard';
import { GoalDetailModal } from './GoalDetailModal';
import { GoalFilters } from './GoalFilters';
import { GoalForm } from './GoalForm';
import { GoalsPageSkeleton } from './GoalsPageSkeleton';
import { CorrectionConfirmation } from './history/CorrectionConfirmation';
import { GoalHistoryModal } from './history/GoalHistoryModal';
import { EmergencyFundCard } from './emergency-fund/EmergencyFundCard';
import { goalErrorKind, goalErrorMismatch, goalRequestError } from './goalError';
import { ReferenceIncomeNote } from './ReferenceIncomeNote';
import styles from './GoalsPage.module.css';
import { currentMonthIn } from './goalFormFields';
import { GoalFormSkeleton } from './GoalFormSkeleton';
import { GoalStatusTableSkeleton } from './GoalStatusTableSkeleton';
import { GoalHistoryTableSkeleton } from './history/GoalHistoryTableSkeleton';

// The server caps the current goals at 100 and a page at 100: one page holds the whole current list.
const GOALS_PAGE_SIZE = 100;

type Editor = GoalSummary | 'create' | null;
export function GoalsPage() {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const timeZone = useWorkspaceTimeZone();
  const workspaceMonth = currentMonthIn(timeZone);
  const [periodicity, setPeriodicity] = useState<GoalPeriodicity | null>(null);
  const [includeEnded, setIncludeEnded] = useState(false);
  const [detailTarget, setDetailTarget] = useState<GoalSummary | null>(null);
  const [editor, setEditor] = useState<Editor>(null);
  const [historyTarget, setHistoryTarget] = useState<GoalSummary | null>(null);
  const formArea = useRef<HTMLDivElement>(null);
  const wasConfirming = useRef(false);
  const [correction, setCorrection] = useState<{
    body: UpdateGoalRequest;
    preview: GoalCorrectionPreview;
  } | null>(null);
  const [correctionConfirmed, setCorrectionConfirmed] = useState(false);
  const [saved, setSaved] = useState(false);
  // Leaving the confirmation step hands the focus back to the form it hid.
  useEffect(() => {
    if (correction === null && wasConfirming.current) {
      formArea.current?.querySelector<HTMLInputElement>('input[type="month"]')?.focus();
    }
    wasConfirming.current = correction !== null;
  }, [correction]);
  const baseCurrency = useWorkspaceBaseCurrency();
  const goals = useQuery({
    queryKey: ['goals', periodicity, includeEnded],
    queryFn: async ({ signal }) => {
      const result = await listGoals({
        ...authApiOptions(),
        query: {
          ...(periodicity === null ? {} : { periodicity }),
          current: !includeEnded,
          page: 1,
          pageSize: GOALS_PAGE_SIZE,
        },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const failedQuery = goals.isError ? goals : null;
  const listPending = goals.isPending && goals.data === undefined;
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
    enabled: detailTarget !== null,
    queryKey: ['goal-status', detailTarget?.id],
    queryFn: async ({ signal }) => {
      const result = await readGoalStatus({
        ...authApiOptions(),
        path: { id: detailTarget!.id },
        signal,
      });
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    retry: false,
  });
  const history = useQuery({
    enabled: historyTarget !== null,
    queryKey: ['goal-versions', historyTarget?.id],
    queryFn: async ({ signal }) => {
      const result = await listGoalVersions({
        ...authApiOptions(),
        path: { id: historyTarget!.id },
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
  // A refused version means another session changed the goal: reload it so the preserved draft is
  // resubmitted against the current version instead of repeating the same conflict.
  const reloadOnConflict = (error: unknown) => {
    if (goalErrorKind(error, true) === 'conflict') {
      void queryClient.invalidateQueries({ queryKey: ['goal'] });
      void queryClient.invalidateQueries({ queryKey: ['goals'] });
      void queryClient.invalidateQueries({ queryKey: ['goal-versions'] });
    }
  };
  const submission = useSubmissionKey();
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
              createGoal({
                ...authApiOptions(),
                headers: {
                  ...authApiOptions().headers,
                  'Idempotency-Key': submission.keyFor(body),
                },
                body: body as CreateGoalRequest,
              }),
            );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return result.data;
    },
    onSuccess: async () => {
      submission.release();
      setEditor(null);
      setSaved(true);
      await refresh();
    },
    onError: reloadOnConflict,
  });
  const preview = useMutation({
    mutationFn: async (body: UpdateGoalRequest) => {
      const result = await withCsrfRetry(() =>
        previewGoalCorrection({
          ...authApiOptions(),
          path: { id: (editor as GoalSummary).id },
          body,
        }),
      );
      if (!result.response?.ok || !result.data) throw goalRequestError(result);
      return { body, preview: result.data };
    },
    onSuccess: (result) => {
      // No month changes: there is no impact to confirm, so the label-only edit is saved directly.
      if (result.preview.months.length === 0) {
        save.mutate(result.body);
        return;
      }
      setCorrectionConfirmed(false);
      setCorrection(result);
    },
    onError: reloadOnConflict,
  });
  const errorKind = goalErrorKind(failedQuery?.error, failedQuery !== null);
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
      <HeaderActions>
        <button
          className="primary-action"
          onClick={() => {
            save.reset();
            setEditor('create');
          }}
          type="button"
        >
          <Icon name="add" size={18} />
          {t('goals.add')}
        </button>
      </HeaderActions>
      <EmergencyFundCard />
      {saved ? <Toast onDismiss={() => setSaved(false)}>{t('goals.saved')}</Toast> : null}
      <GoalFilters
        includeEnded={includeEnded}
        onIncludeEndedChange={setIncludeEnded}
        onPeriodicityChange={setPeriodicity}
        periodicity={periodicity}
      />
      {listPending ? (
        <GoalsPageSkeleton status={t('goals.loading')} />
      ) : failedQuery ? (
        <section className={`card ${styles.state}`} role="alert">
          <h3>{t(errorKind === 'unauthorized' ? 'goals.unauthorized' : 'goals.error')}</h3>
          {errorKind !== 'unauthorized' ? (
            <button
              className="secondary-action"
              onClick={() => void failedQuery.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          ) : null}
        </section>
      ) : items.length === 0 ? null : (
        <section className={styles.list} aria-label={t('goals.list')}>
          <div className={styles.grid}>
            {items.map((goal) => (
              <GoalCard
                currentMonth={workspaceMonth}
                goal={goal}
                key={goal.id}
                onDetail={setDetailTarget}
                onEdit={(target) => {
                  save.reset();
                  preview.reset();
                  setCorrection(null);
                  setCorrectionConfirmed(false);
                  setEditor(target);
                }}
                onHistory={setHistoryTarget}
              />
            ))}
          </div>
          {goals.data && goals.data.total > items.length ? (
            <p className={styles.notice}>{t('goals.truncated', { count: items.length })}</p>
          ) : null}
        </section>
      )}
      {editor ? (
        <Modal
          intent={editor === 'create' ? 'create' : 'edit'}
          close={() => {
            setEditor(null);
            save.reset();
            preview.reset();
            setCorrection(null);
            setCorrectionConfirmed(false);
          }}
          title={t(editor === 'create' ? 'goals.createTitle' : 'goals.editTitle')}
        >
          {detail.data ? <ReferenceIncomeNote goal={detail.data as GoalDetail} /> : null}
          {editingSummary && !latestDetail && detail.isPending ? (
            <GoalFormSkeleton baseCurrency={baseCurrency} goal={editingSummary} />
          ) : (
            <>
              <div hidden={correction !== null} ref={formArea}>
                <GoalForm
                  baseCurrency={baseCurrency}
                  goal={editor === 'create' ? undefined : (editingGoal ?? editor)}
                  key={
                    editor === 'create'
                      ? 'create'
                      : `${editor.id}-${latestDetail ? 'detail' : 'summary'}`
                  }
                  onSubmit={(body) => {
                    if (
                      editor !== 'create' &&
                      (body as UpdateGoalRequest).effectiveFrom! < workspaceMonth
                    ) {
                      preview.mutate(body as UpdateGoalRequest);
                      return;
                    }
                    save.mutate(body);
                  }}
                  pending={save.isPending || preview.isPending}
                  submitError={goalErrorKind(
                    save.error ?? preview.error,
                    save.isError || preview.isError,
                  )}
                  submitMismatch={goalErrorMismatch(save.error ?? preview.error)}
                />
              </div>
              {correction ? (
                <CorrectionConfirmation
                  assetCode={(editor as GoalSummary).assetCode}
                  confirmed={correctionConfirmed}
                  error={save.isError ? (goalErrorKind(save.error, true) ?? 'network') : null}
                  onBack={() => {
                    setCorrection(null);
                    setCorrectionConfirmed(false);
                    save.reset();
                    preview.reset();
                  }}
                  onConfirm={() => save.mutate(correction.body)}
                  onConfirmedChange={setCorrectionConfirmed}
                  pending={save.isPending}
                  preview={correction.preview}
                />
              ) : null}
            </>
          )}
        </Modal>
      ) : null}
      {historyTarget ? (
        history.isPending ? (
          <Modal
            intent="detail"
            close={() => setHistoryTarget(null)}
            title={t('goals.history.title')}
          >
            <GoalHistoryTableSkeleton />
          </Modal>
        ) : history.data ? (
          <GoalHistoryModal
            assetCode={historyTarget.assetCode}
            close={() => setHistoryTarget(null)}
            items={history.data.items}
          />
        ) : (
          <Modal
            intent="detail"
            close={() => setHistoryTarget(null)}
            title={t('goals.history.title')}
          >
            <p role="alert">{t('goals.error')}</p>
          </Modal>
        )
      ) : null}
      {detailTarget ? (
        statusDetail.isPending ? (
          <Modal intent="detail" close={() => setDetailTarget(null)} title={t('goals.detailTitle')}>
            <GoalStatusTableSkeleton periodicity={detailTarget.periodicity} />
          </Modal>
        ) : statusDetail.data ? (
          <GoalDetailModal
            assetCode={detailTarget.assetCode}
            close={() => setDetailTarget(null)}
            status={statusDetail.data}
          />
        ) : (
          <Modal intent="detail" close={() => setDetailTarget(null)} title={t('goals.detailTitle')}>
            <p role="alert">{t('goals.error')}</p>
          </Modal>
        )
      ) : null}
    </div>
  );
}
