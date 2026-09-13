import {
  applyCategorizationRules,
  previewCategorizationRules,
  type CategorizationRule,
} from '@cadran/api-client';
import { useMutation } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  CategorizationRuleRequestError,
  categorizationRuleRequestError,
} from '@/features/categorization-rules/categorizationRuleError';
import { useRefreshCategorizationData } from '@/features/categorization-rules/refreshCategorizationData';

/**
 * A preview or apply refused for exceeding the execution budget, or refused
 * as stale, can still reflect a rule the backend deactivated before
 * refusing the request (a budget breach deactivates the offending rule as
 * it is detected). The rule list is refreshed on both so it never keeps
 * showing a rule as active after the backend has already turned it off.
 */
function isDeactivatingErrorKind(error: unknown): boolean {
  return (
    error instanceof CategorizationRuleRequestError &&
    (error.kind === 'executionLimit' || error.kind === 'stale')
  );
}

/**
 * Previewing a rule's (or every rule's) effect over a date range before
 * committing it. The preview token is bound to the exact scope and rows it
 * was computed for, so data changed after the preview is surfaced as stale
 * rather than applied against a result that no longer matches.
 */
export function useRulePreview(onApplied: () => void) {
  const refresh = useRefreshCategorizationData();
  const [target, setTarget] = useState<CategorizationRule | null | undefined>(undefined);
  const [stale, setStale] = useState(false);

  const preview = useMutation({
    mutationFn: async ({ from, to }: { from: string; to: string }) => {
      const result = await withCsrfRetry(() =>
        previewCategorizationRules({
          ...authApiOptions(),
          body: { from, ruleId: target?.id ?? null, to },
        }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async (error) => {
      if (isDeactivatingErrorKind(error)) await refresh();
    },
    onSuccess: async (data) => {
      setStale(false);
      if (data.deactivatedRuleIds.length > 0) await refresh();
    },
  });

  const apply = useMutation({
    mutationFn: async (previewToken: string) => {
      const result = await withCsrfRetry(() =>
        applyCategorizationRules({ ...authApiOptions(), body: { previewToken } }),
      );
      if (!result.response?.ok || !result.data) throw categorizationRuleRequestError(result);
      return result.data;
    },
    onError: async (error) => {
      if (error instanceof CategorizationRuleRequestError && error.kind === 'stale') {
        setStale(true);
      }
      if (isDeactivatingErrorKind(error)) await refresh();
    },
    onSuccess: async () => {
      onApplied();
      await refresh();
    },
  });

  return {
    apply,
    close: () => {
      setTarget(undefined);
      preview.reset();
      apply.reset();
      setStale(false);
    },
    open: (rule: CategorizationRule | null) => {
      preview.reset();
      apply.reset();
      setStale(false);
      setTarget(rule);
    },
    preview,
    requestPreview: (from: string, to: string) => {
      apply.reset();
      setStale(false);
      preview.mutate({ from, to });
    },
    stale,
    target,
  };
}
