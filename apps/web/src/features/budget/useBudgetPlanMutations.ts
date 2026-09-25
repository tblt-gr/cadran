import {
  activateBudgetPlan,
  closeBudgetPlan,
  createBudgetPlan,
  createBudgetTarget,
  updateBudgetPlan,
  updateBudgetTarget,
  type BudgetPlan,
  type BudgetTargetDetail,
  type CreateBudgetPlanRequest,
  type CreateBudgetTargetRequest,
  type UpdateBudgetPlanRequest,
  type UpdateBudgetTargetRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { budgetRequestError } from './budgetError';

type PlanEditor = BudgetPlan | 'create' | null;
type TargetEditor = BudgetTargetDetail | 'create' | null;

function failed(result: { response?: Response; data?: unknown }) {
  return !result.response?.ok || !result.data;
}

/**
 * The three mutations BudgetPage offers: saving a plan, saving a target and
 * changing a plan's lifecycle. Grouped here because they share the same
 * invalidation (the plan list, the open detail and its comparisons) and the
 * same "close the editor, toast, refresh" success shape.
 */
export function useBudgetPlanMutations({
  planId,
  planEditor,
  targetEditor,
  closePlanEditor,
  closeTargetEditor,
  notify,
}: {
  planId: string | undefined;
  planEditor: PlanEditor;
  targetEditor: TargetEditor;
  closePlanEditor: () => void;
  closeTargetEditor: () => void;
  notify: (message: string) => void;
}) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
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
      closePlanEditor();
      notify(t('budget.toasts.saved'));
      await refresh();
      if (!planId) {
        window.history.pushState({}, '', `/budget/plans/${plan.id}`);
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
      closeTargetEditor();
      notify(t('budget.toasts.saved'));
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
      notify(t('budget.toasts.stateChanged'));
      await refresh();
    },
  });

  return { planSave, targetSave, lifecycle };
}
