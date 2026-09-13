import {
  createCategorizationRule,
  updateCategorizationRule,
  type CategorizationRule,
  type CreateCategorizationRuleRequest,
  type UpdateCategorizationRuleRequest,
} from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  CategorizationRuleRequestError,
  categorizationRuleRequestError,
} from '@/features/categories/rules/categorizationRuleError';
import { useRefreshCategorizationData } from '@/features/categories/rules/refreshCategorizationData';

type RuleEditorTarget = CategorizationRule | 'create' | null;

/**
 * Creating or editing one categorization rule. Opening is refused while the
 * account scope the form needs is not yet available, so it is never shown a
 * partial or unresolved set of accounts to choose a scope from.
 */
export function useRuleEditor(accountsAvailable: boolean, onSaved: () => void) {
  const refresh = useRefreshCategorizationData();
  const [target, setTarget] = useState<RuleEditorTarget>(null);

  const save = useMutation({
    mutationFn: async (body: CreateCategorizationRuleRequest | UpdateCategorizationRuleRequest) => {
      const result =
        target && target !== 'create'
          ? await withCsrfRetry(() =>
              updateCategorizationRule({
                ...authApiOptions(),
                path: { id: target.id },
                body: body as UpdateCategorizationRuleRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createCategorizationRule({
                ...authApiOptions(),
                body: body as CreateCategorizationRuleRequest,
              }),
            );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async (error) => {
      if (error instanceof CategorizationRuleRequestError && error.kind === 'stale') {
        await refresh();
      }
    },
    onSuccess: async () => {
      setTarget(null);
      onSaved();
      await refresh();
    },
  });

  return {
    close: () => {
      setTarget(null);
      save.reset();
    },
    open: (next: Exclude<RuleEditorTarget, null>) => {
      if (!accountsAvailable) return;
      save.reset();
      setTarget(next);
    },
    save,
    target,
  };
}
