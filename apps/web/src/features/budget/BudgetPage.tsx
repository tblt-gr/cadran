import {
  activateBudgetPlan,
  closeBudgetPlan,
  createBudgetPlan,
  createBudgetTarget,
  listBudgetPlans,
  readBudgetPlan,
  updateBudgetPlan,
  updateBudgetTarget,
  type BudgetPlan,
  type BudgetPlanDetail,
  type BudgetTargetDetail,
  type CreateBudgetPlanRequest,
  type CreateBudgetTargetRequest,
  type UpdateBudgetPlanRequest,
  type UpdateBudgetTargetRequest,
} from '@cadran/api-client';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { BudgetPlanForm } from './budget-plan-form/BudgetPlanForm';
import { BudgetComparisonsPanel } from './budget-comparisons/BudgetComparisonsPanel';
import { BudgetTargetForm } from './budget-target-form/BudgetTargetForm';
import { budgetErrorKind, budgetRequestError, BudgetRequestError } from './budgetError';
import styles from './BudgetPage.module.css';

type PlanEditor = BudgetPlan | 'create' | null;
type TargetEditor = BudgetTargetDetail | 'create' | null;
const tones = { DRAFT: 'info', ACTIVE: 'positive', CLOSED: 'warning' } as const;

function failed(result: { response?: Response; data?: unknown }) {
  return !result.response?.ok || !result.data;
}

export function BudgetPage({ planId }: { planId?: string }) {
  const { t, i18n } = useTranslation();
  const queryClient = useQueryClient();
  const [planEditor, setPlanEditor] = useState<PlanEditor>(null);
  const [targetEditor, setTargetEditor] = useState<TargetEditor>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const plans = useQuery({
    queryKey: ['budget-plans'],
    queryFn: async ({ signal }) => {
      const result = await listBudgetPlans({
        ...authApiOptions(),
        query: { page: 1, perPage: 100 },
        signal,
      });
      if (failed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    retry: false,
  });
  const detail = useQuery({
    enabled: Boolean(planId),
    queryKey: ['budget-plan', planId],
    queryFn: async ({ signal }) => {
      const result = await readBudgetPlan({ ...authApiOptions(), path: { id: planId! }, signal });
      if (failed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    retry: false,
  });
  const refresh = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['budget-plans'] }),
      queryClient.invalidateQueries({ queryKey: ['budget-plan', planId] }),
      ...(planId
        ? [queryClient.invalidateQueries({ queryKey: ['budget-comparisons', planId] })]
        : []),
    ]);
  };
  const planSave = useMutation({
    mutationFn: async (body: CreateBudgetPlanRequest | UpdateBudgetPlanRequest) => {
      const result =
        planEditor && planEditor !== 'create'
          ? await withCsrfRetry(() =>
              updateBudgetPlan({
                ...authApiOptions(),
                path: { id: planEditor.id },
                body: body as UpdateBudgetPlanRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createBudgetPlan({ ...authApiOptions(), body: body as CreateBudgetPlanRequest }),
            );
      if (failed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    onSuccess: async (plan) => {
      setPlanEditor(null);
      setNotice(t('budget.toasts.saved'));
      await refresh();
      if (!planId) {
        window.history.pushState({}, '', `/budget/${plan.id}`);
        window.dispatchEvent(new PopStateEvent('popstate'));
      }
    },
  });
  const targetSave = useMutation({
    mutationFn: async (body: CreateBudgetTargetRequest | UpdateBudgetTargetRequest) => {
      const result =
        targetEditor && targetEditor !== 'create'
          ? await withCsrfRetry(() =>
              updateBudgetTarget({
                ...authApiOptions(),
                path: { id: targetEditor.id },
                body: body as UpdateBudgetTargetRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createBudgetTarget({
                ...authApiOptions(),
                path: { planId: planId! },
                body: body as CreateBudgetTargetRequest,
              }),
            );
      if (failed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    onSuccess: async () => {
      setTargetEditor(null);
      setNotice(t('budget.toasts.saved'));
      await refresh();
    },
  });
  const lifecycle = useMutation({
    mutationFn: async (action: 'activate' | 'close') => {
      const result = await withCsrfRetry(() =>
        action === 'activate'
          ? activateBudgetPlan({ ...authApiOptions(), path: { id: planId! }, body: {} })
          : closeBudgetPlan({ ...authApiOptions(), path: { id: planId! }, body: {} }),
      );
      if (failed(result)) throw budgetRequestError(result);
      return result.data!;
    },
    onSuccess: async () => {
      setNotice(t('budget.toasts.stateChanged'));
      await refresh();
    },
  });
  const error =
    plans.error instanceof BudgetRequestError
      ? plans.error
      : detail.error instanceof BudgetRequestError
        ? detail.error
        : null;
  if (plans.isPending || (planId && detail.isPending))
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('budget.loading')}</h2>
      </section>
    );
  if (plans.isError || detail.isError)
    return (
      <section className={`card ${styles.state}`} role="alert">
        <h2>
          {t(error?.kind === 'unauthorized' ? 'budget.unauthorized.title' : 'budget.error.title')}
        </h2>
        <p>
          {t(
            error?.kind === 'unauthorized'
              ? 'budget.unauthorized.description'
              : 'budget.error.description',
          )}
        </p>
        {error?.kind !== 'unauthorized' ? (
          <button
            className="secondary-action"
            onClick={() => void (planId ? detail.refetch() : plans.refetch())}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        ) : null}
      </section>
    );
  const selected = detail.data;
  const planItems = plans.data?.items ?? [];
  return (
    <div className={styles.page}>
      {notice ? <Toast onDismiss={() => setNotice(null)}>{notice}</Toast> : null}
      <section className={styles.intro}>
        <div>
          <p>{t('budget.eyebrow')}</p>
          <h2>
            {selected ? t('budget.detailTitle', { period: selected.period }) : t('budget.title')}
          </h2>
          <span>{t(selected ? 'budget.detailDescription' : 'budget.description')}</span>
        </div>
        <div className={styles.actions}>
          {selected ? (
            <a
              className="secondary-action"
              href="/budget"
              onClick={(event) => handleClientNavigation(event, '/budget')}
            >
              {t('budget.back')}
            </a>
          ) : null}
          <button className="primary-action" onClick={() => setPlanEditor('create')} type="button">
            {t('budget.addPlan')}
          </button>
        </div>
      </section>
      {selected ? (
        <PlanDetail
          detail={selected}
          language={i18n.language}
          onEditPlan={() => setPlanEditor(selected)}
          onEditTarget={setTargetEditor}
          onNewTarget={() => setTargetEditor('create')}
          onLifecycle={(action) => lifecycle.mutate(action)}
          lifecycleError={budgetErrorKind(lifecycle.error, lifecycle.isError)}
          lifecyclePending={lifecycle.isPending}
        />
      ) : planItems.length === 0 ? (
        <section className={`card ${styles.state}`}>
          <h2>{t('budget.empty.title')}</h2>
          <p>{t('budget.empty.description')}</p>
          <button className="primary-action" onClick={() => setPlanEditor('create')} type="button">
            {t('budget.addFirst')}
          </button>
        </section>
      ) : (
        <table className={styles.table}>
          <thead>
            <tr>
              <th>{t('budget.columns.period')}</th>
              <th>{t('budget.columns.currency')}</th>
              <th>{t('budget.columns.state')}</th>
              <th>
                <span className="sr-only">{t('actions.more')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {planItems.map((plan) => (
              <tr key={plan.id}>
                <td>
                  <a
                    href={`/budget/${plan.id}`}
                    onClick={(event) => handleClientNavigation(event, `/budget/${plan.id}`)}
                  >
                    {plan.period}
                  </a>
                </td>
                <td>{plan.assetCode}</td>
                <td>
                  <StatusBadge tone={tones[plan.state]}>
                    {t(`budget.states.${plan.state}`)}
                  </StatusBadge>
                </td>
                <td>
                  <button
                    className="secondary-action"
                    onClick={() => setPlanEditor(plan)}
                    type="button"
                  >
                    {t('budget.edit')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {planEditor ? (
        <Modal
          close={() => {
            setPlanEditor(null);
            planSave.reset();
          }}
          eyebrow={t(
            planEditor === 'create' ? 'budget.form.createEyebrow' : 'budget.form.editEyebrow',
          )}
          title={t(planEditor === 'create' ? 'budget.form.createTitle' : 'budget.form.editTitle')}
        >
          <BudgetPlanForm
            plan={planEditor === 'create' ? undefined : planEditor}
            pending={planSave.isPending}
            submitError={budgetErrorKind(planSave.error, planSave.isError)}
            onSubmit={(body) => planSave.mutate(body)}
          />
        </Modal>
      ) : null}
      {targetEditor ? (
        <Modal
          close={() => {
            setTargetEditor(null);
            targetSave.reset();
          }}
          eyebrow={t(
            targetEditor === 'create' ? 'budget.target.createEyebrow' : 'budget.target.editEyebrow',
          )}
          title={t(
            targetEditor === 'create' ? 'budget.target.createTitle' : 'budget.target.editTitle',
          )}
        >
          <BudgetTargetForm
            target={targetEditor === 'create' ? undefined : targetEditor}
            pending={targetSave.isPending}
            submitError={budgetErrorKind(targetSave.error, targetSave.isError)}
            onSubmit={(body) => targetSave.mutate(body)}
          />
        </Modal>
      ) : null}
    </div>
  );
}

function PlanDetail({
  detail,
  language,
  onEditPlan,
  onEditTarget,
  onNewTarget,
  onLifecycle,
  lifecycleError,
  lifecyclePending,
}: {
  detail: BudgetPlanDetail;
  language: string;
  onEditPlan: () => void;
  onEditTarget: (target: BudgetTargetDetail) => void;
  onNewTarget: () => void;
  onLifecycle: (action: 'activate' | 'close') => void;
  lifecycleError: ReturnType<typeof budgetErrorKind>;
  lifecyclePending: boolean;
}) {
  const { t } = useTranslation();
  const activationHintId = `budget-plan-${detail.id}-activation-hint`;
  const emptyDraft = detail.state === 'DRAFT' && detail.targets.length === 0;
  return (
    <>
      <section className={`card ${styles.summary}`}>
        <div>
          <span>{t('budget.columns.currency')}</span>
          <strong>{detail.assetCode}</strong>
        </div>
        <div>
          <span>{t('budget.columns.state')}</span>
          <StatusBadge tone={tones[detail.state]}>{t(`budget.states.${detail.state}`)}</StatusBadge>
        </div>
        <div className={styles.summaryActions}>
          {detail.state === 'DRAFT' ? (
            <>
              <button className="secondary-action" onClick={onEditPlan} type="button">
                {t('budget.edit')}
              </button>
              <button
                aria-describedby={emptyDraft ? activationHintId : undefined}
                className="primary-action"
                disabled={lifecyclePending || emptyDraft}
                onClick={() => onLifecycle('activate')}
                type="button"
              >
                {t('budget.activate')}
              </button>
            </>
          ) : null}
          {detail.state === 'ACTIVE' ? (
            <button
              className="secondary-action"
              disabled={lifecyclePending}
              onClick={() => onLifecycle('close')}
              type="button"
            >
              {t('budget.close')}
            </button>
          ) : null}
        </div>
        {lifecycleError ? (
          <p className={styles.lifecycleError} role="alert">
            {t(`budget.errors.${lifecycleError}`)}
          </p>
        ) : null}
      </section>
      <section className={styles.targets}>
        <div className={styles.targetHeading}>
          <div>
            <h3>{t('budget.targets.title')}</h3>
            <p>{t('budget.targets.description')}</p>
          </div>
          {detail.state !== 'CLOSED' ? (
            <button className="primary-action" onClick={onNewTarget} type="button">
              {t('budget.targets.add')}
            </button>
          ) : null}
        </div>
        {detail.targets.length === 0 ? (
          <p className={styles.emptyTargets} id={emptyDraft ? activationHintId : undefined}>
            {t('budget.targets.empty')}
          </p>
        ) : (
          <div className={styles.tableWrap}>
            <table className={styles.table}>
              <thead>
                <tr>
                  <th>{t('budget.columns.scope')}</th>
                  <th>{t('budget.columns.value')}</th>
                  <th>{t('budget.columns.resolved')}</th>
                  <th>{t('budget.columns.warning')}</th>
                  <th>
                    <span className="sr-only">{t('actions.more')}</span>
                  </th>
                </tr>
              </thead>
              <tbody>
                {detail.targets.map((target) => (
                  <tr key={target.id}>
                    <td>
                      {t(`budget.scopeTypes.${target.scopeType}`)} · {target.scopeLabel}
                    </td>
                    <td>
                      {target.valueType === 'AMOUNT' && target.storedAmount ? (
                        <MoneyValue
                          value={formatAmount(target.storedAmount, detail.assetCode, language)}
                        />
                      ) : target.storedRatio ? (
                        formatRatioPercentage(target.storedRatio, language)
                      ) : (
                        '—'
                      )}
                    </td>
                    <td>
                      {target.resolvedAmount === null ? (
                        <span>{t('budget.nonCalculable')}</span>
                      ) : (
                        <MoneyValue
                          value={formatAmount(target.resolvedAmount, detail.assetCode, language)}
                        />
                      )}
                    </td>
                    <td>
                      {target.overlapping || target.nonCalculableReason ? (
                        <div className={styles.warnings}>
                          {target.overlapping ? (
                            <span className={styles.warning}>{t('budget.overlap')}</span>
                          ) : null}
                          {target.nonCalculableReason ? (
                            <span className={styles.warning}>
                              {t(`budget.reasons.${target.nonCalculableReason}`)}
                            </span>
                          ) : null}
                        </div>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td>
                      {detail.state !== 'CLOSED' ? (
                        <button
                          className="secondary-action"
                          onClick={() => onEditTarget(target)}
                          type="button"
                        >
                          {t('budget.edit')}
                        </button>
                      ) : null}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      {detail.periodType === 'MONTH' ? (
        <section className={styles.comparisons} aria-labelledby="budget-comparisons-title">
          <div className={styles.targetHeading}>
            <div>
              <h3 id="budget-comparisons-title">{t('budget.comparisons.title')}</h3>
              <p>{t('budget.comparisons.description')}</p>
            </div>
          </div>
          <BudgetComparisonsPanel planId={detail.id} />
        </section>
      ) : null}
    </>
  );
}
