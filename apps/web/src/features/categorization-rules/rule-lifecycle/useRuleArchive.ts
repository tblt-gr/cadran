import { archiveCategorizationRule, type CategorizationRule } from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { categorizationRuleRequestError } from '@/features/categorization-rules/categorizationRuleError';
import { useRefreshCategorizationData } from '@/features/categorization-rules/refreshCategorizationData';

/**
 * Archiving one categorization rule, confirmed against the version the
 * caller last saw so a rule changed elsewhere in the meantime is refused
 * instead of archived blind.
 */
export function useRuleArchive(onArchived: () => void) {
  const refresh = useRefreshCategorizationData();
  const [target, setTarget] = useState<CategorizationRule | null>(null);

  const archive = useMutation({
    mutationFn: async (rule: CategorizationRule) => {
      const result = await withCsrfRetry(() =>
        archiveCategorizationRule({
          ...authApiOptions(),
          path: { id: rule.id },
          body: { version: rule.version },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async () => refresh(),
    onSuccess: async () => {
      setTarget(null);
      onArchived();
      await refresh();
    },
  });

  return {
    archive,
    close: () => {
      setTarget(null);
      archive.reset();
    },
    open: (rule: CategorizationRule) => {
      archive.reset();
      setTarget(rule);
    },
    target,
  };
}
