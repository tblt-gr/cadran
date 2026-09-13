import { updateCategorizationRule, type CategorizationRule } from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { categorizationRuleRequestError } from '@/features/categories/rules/categorizationRuleError';
import { useRefreshCategorizationData } from '@/features/categories/rules/refreshCategorizationData';

/**
 * Toggling one categorization rule's active state. The rule's other fields
 * are resubmitted unchanged alongside the flipped flag and the version the
 * caller last saw, so a stale toggle is refused rather than silently
 * clobbering a concurrent edit.
 */
export function useRuleActivation(onToggled: () => void) {
  const refresh = useRefreshCategorizationData();
  const [target, setTarget] = useState<CategorizationRule | null>(null);

  const activation = useMutation({
    mutationFn: async (rule: CategorizationRule) => {
      const { id, ...body } = rule;
      const result = await withCsrfRetry(() =>
        updateCategorizationRule({
          ...authApiOptions(),
          path: { id },
          body: {
            accountScope: body.accountScope,
            active: !rule.active,
            conditions: body.conditions,
            effectiveFrom: body.effectiveFrom,
            effectiveTo: body.effectiveTo,
            label: body.label,
            priority: body.priority,
            targetAxes: body.targetAxes,
            targetCategoryId: body.targetCategoryId,
            targetCounterparty: body.targetCounterparty,
            version: body.version,
          },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async () => refresh(),
    onSuccess: async () => {
      setTarget(null);
      onToggled();
      await refresh();
    },
  });

  return {
    activation,
    close: () => {
      setTarget(null);
      activation.reset();
    },
    open: (rule: CategorizationRule) => {
      activation.reset();
      setTarget(rule);
    },
    target,
  };
}
