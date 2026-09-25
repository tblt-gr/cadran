import {
  archiveAccount,
  createAccount,
  recordAccountBalance,
  updateAccount,
  type Account,
  type CreateAccountRequest,
  type RecordAccountBalanceRequest,
  type UpdateAccountRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { accountRequestError, AccountRequestError, requestFailed } from './accountError';

type Editor = Account | 'create' | null;

/**
 * The three mutations AccountsPage offers: create/edit, archive and record a
 * balance. Grouped here because they share the same invalidation (the account
 * list) and the same stale-state recovery: a stale version or an already
 * archived account means the list is behind the server, so the next attempt
 * should read current data instead of failing the same way again.
 */
export function useAccountsPageMutations({
  editor,
  closeEditor,
  closeArchive,
  closeRecording,
  notify,
}: {
  editor: Editor;
  closeEditor: () => void;
  closeArchive: () => void;
  closeRecording: () => void;
  notify: (message: 'saved' | 'archived' | 'recorded') => void;
}) {
  const queryClient = useQueryClient();

  async function refreshOnStaleState(error: unknown) {
    if (
      error instanceof AccountRequestError &&
      (error.kind === 'stale' || error.kind === 'archived')
    ) {
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    }
  }

  const save = useMutation({
    mutationFn: async (body: CreateAccountRequest | UpdateAccountRequest) => {
      const result =
        editor && editor !== 'create'
          ? await withCsrfRetry(() =>
              updateAccount({
                ...authApiOptions(),
                path: { id: editor.id },
                body: body as UpdateAccountRequest,
              }),
            )
          : await withCsrfRetry(() =>
              createAccount({ ...authApiOptions(), body: body as CreateAccountRequest }),
            );

      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      closeEditor();
      notify('saved');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  const archive = useMutation({
    mutationFn: async (account: Account) => {
      const result = await withCsrfRetry(() =>
        archiveAccount({
          ...authApiOptions(),
          path: { id: account.id },
          body: { version: account.version },
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      closeArchive();
      notify('archived');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  const recordBalance = useMutation({
    mutationFn: async ({
      account,
      body,
    }: {
      account: Account;
      body: RecordAccountBalanceRequest;
    }) => {
      const result = await withCsrfRetry(() =>
        recordAccountBalance({ ...authApiOptions(), path: { id: account.id }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onError: refreshOnStaleState,
    onSuccess: async () => {
      closeRecording();
      notify('recorded');
      await queryClient.invalidateQueries({ queryKey: ['accounts'] });
    },
  });

  return { save, archive, recordBalance };
}
