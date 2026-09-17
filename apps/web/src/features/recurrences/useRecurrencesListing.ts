import { detectRecurrenceCandidates, listAccounts, listRecurrences } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';

function failed(result: { response?: Response }) {
  return new Error(`recurrence:${result.response?.status ?? 0}`);
}

/**
 * The recurrences screen's four read queries — detected candidates, active recurrences, the
 * archived ones (fetched only once the user asks to see them) and the accounts a new or
 * edited recurrence can name — plus the derived loading and error state the page renders from.
 */
export function useRecurrencesListing() {
  const [showArchived, setShowArchived] = useState(false);

  const candidates = useQuery({
    queryKey: ['recurrence-candidates'],
    queryFn: async ({ signal }) => {
      const result = await detectRecurrenceCandidates({ ...authApiOptions(), signal });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    retry: false,
  });
  const recurrences = useQuery({
    queryKey: ['recurrences', 'active'],
    queryFn: async ({ signal }) => {
      const result = await listRecurrences({
        ...authApiOptions(),
        query: { includeArchived: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data;
    },
    retry: false,
  });
  const archivedRecurrences = useQuery({
    enabled: showArchived,
    queryKey: ['recurrences', 'archived'],
    queryFn: async ({ signal }) => {
      const result = await listRecurrences({
        ...authApiOptions(),
        query: { includeArchived: true, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return {
        ...result.data,
        items: result.data.items.filter((recurrence) => recurrence.archivedAt !== null),
      };
    },
    retry: false,
  });
  const accounts = useQuery({
    queryKey: ['accounts', 'recurrences'],
    queryFn: async ({ signal }) => {
      const result = await listAccounts({
        ...authApiOptions(),
        query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw failed(result);
      return result.data.items;
    },
    retry: false,
  });

  function refetchAll() {
    void candidates.refetch();
    void recurrences.refetch();
    void accounts.refetch();
  }

  return {
    accounts,
    active: recurrences.data?.items ?? [],
    archived: archivedRecurrences.data?.items ?? [],
    archivedRecurrences,
    candidates,
    hasError: candidates.isError || recurrences.isError || accounts.isError,
    loading: candidates.isPending || recurrences.isPending || accounts.isPending,
    recurrences,
    refetchAll,
    setShowArchived,
    showArchived,
  };
}
