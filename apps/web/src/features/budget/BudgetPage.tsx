import {
  listBudgetPlans,
  readBudgetPlan,
  type BudgetPlan,
  type BudgetTargetDetail,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { Toast } from '@/components/ui/toast/Toast';
import { authApiOptions } from '@/features/auth/apiOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { BudgetPlanForm } from './budget-plan-form/BudgetPlanForm';
import { BudgetTargetForm } from './budget-target-form/BudgetTargetForm';
import { budgetErrorKind, budgetRequestError, BudgetRequestError } from './budgetError';
import { PlanDetail } from './plan-detail/PlanDetail';
import { useBudgetPlanMutations } from './useBudgetPlanMutations';
import styles from './BudgetPage.module.css';

type PlanEditor = BudgetPlan | 'create' | null;
type TargetEditor = BudgetTargetDetail | 'create' | null;
const tones = { DRAFT: 'info', ACTIVE: 'positive', CLOSED: 'warning' } as const;

function failed(result: { response?: Response; data?: unknown }) {
  return !result.response?.ok || !result.data;
}

export function BudgetPage({ planId }: { planId?: string }) {
  const { t, i18n } = useTranslation();
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
  const { planSave, targetSave, lifecycle } = useBudgetPlanMutations({
    planId,
    planEditor,
    targetEditor,
    closePlanEditor: () => setPlanEditor(null),
    closeTargetEditor: () => setTargetEditor(null),
    notify: setNotice,
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
              href="/budget/plans"
              onClick={(event) => handleClientNavigation(event, '/budget/plans')}
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
                    href={`/budget/plans/${plan.id}`}
                    onClick={(event) => handleClientNavigation(event, `/budget/plans/${plan.id}`)}
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
