import {
  archiveAccountGroup,
  createAccountGroup,
  updateAccountGroup,
  type AccountGroup,
  type CreateAccountGroupRequest,
  type UpdateAccountGroupRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient, type QueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { GroupRequestError, groupRequestError } from './groupError';

interface AccountGroupWritesOptions {
  /** The group being edited, 'create' for a new one, null when no editor is open. */
  editor: AccountGroup | 'create' | null;
  onSaved: () => void;
  onArchived: () => void;
}

async function invalidateGroupViews(queryClient: QueryClient) {
  await queryClient.invalidateQueries({ queryKey: ['account-groups'] });
  await queryClient.invalidateQueries({ queryKey: ['net-worth'] });
  await queryClient.invalidateQueries({ queryKey: ['accounts'] });
  await queryClient.invalidateQueries({ queryKey: ['monthly-recap'] });
  await queryClient.invalidateQueries({ queryKey: ['monthly-ledger'] });
}

/** Create, update and archive writes of the groups page, with the views a group feeds refreshed. */
export function useAccountGroupWrites({ editor, onSaved, onArchived }: AccountGroupWritesOptions) {
  const queryClient = useQueryClient();

  async function refreshOnStaleState(error: unknown) {
    if (error instanceof GroupRequestError && error.kind === 'stale') {
      await queryClient.invalidateQueries({ queryKey: ['account-groups'] });
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateAccountGroupRequest | UpdateAccountGroupRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateAccountGroup({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateAccountGroupRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createAccountGroup({ ...authApiOptions(), body: body as CreateAccountGroupRequest }),
            );

      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onSaved();
      await invalidateGroupViews(queryClient);
    },
    onError: (error) => {
      void refreshOnStaleState(error);
    },
  });

  const archive = useMutation({
    mutationFn: async (group: AccountGroup) => {
      const result = await withCsrfRetry(() =>
        archiveAccountGroup({
          ...authApiOptions(),
          path: { id: group.id },
          body: { version: group.version },
        }),
      );
      if (!result.response?.ok || !result.data) {
        throw groupRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      onArchived();
      await invalidateGroupViews(queryClient);
    },
    onError: (error) => {
      void refreshOnStaleState(error);
    },
  });

  return { save, archive };
}
