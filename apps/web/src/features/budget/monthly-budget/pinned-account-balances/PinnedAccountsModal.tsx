import {
  listAccounts,
  type MonthlyRecapPreferences,
  type MonthlySelectedAccount,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { budgetErrorKind, budgetRequestError } from '@/features/budget/budgetError';
import {
  useRecapPreferences,
  useSaveRecapPreferences,
} from '@/features/budget/monthly-budget/monthly-recap/useMonthlyRecap';
import styles from './PinnedAccountBalances.module.css';

interface Props {
  close: () => void;
  preferences: MonthlyRecapPreferences;
  selectedAccounts: MonthlySelectedAccount[];
}
export function PinnedAccountsModal({ close, preferences, selectedAccounts }: Props) {
  const { t } = useTranslation();
  const save = useSaveRecapPreferences();
  const current = useRecapPreferences();
  const [base, setBase] = useState(preferences);
  const [ids, setIds] = useState<string[]>(
    preferences.visibleAccountIds ?? selectedAccounts.map((a) => a.accountId),
  );
  const [defaults, setDefaults] = useState(preferences.visibleAccountIds === null);
  const [reloading, setReloading] = useState(false);
  const [reloadFailed, setReloadFailed] = useState(false);
  const accounts = useQuery({
    queryKey: ['accounts', 'pinned-chooser'],
    retry: false,
    queryFn: async ({ signal }) => {
      const items = [];
      for (let page = 1; page <= 1000; page++) {
        const result = await listAccounts({
          ...authApiOptions(),
          query: { includeArchived: true, includeClosed: true, page, pageSize: 100 },
          signal,
        });
        if (!result.response?.ok || !result.data) throw budgetRequestError(result);
        items.push(...result.data.items);
        if (items.length >= result.data.total) return items;
        if (result.data.items.length === 0) throw new Error('pinned-accounts');
      }
      throw new Error('pinned-accounts-limit');
    },
  });
  const error = budgetErrorKind(save.error, save.isError);
  async function reload() {
    setReloading(true);
    const result = await current.refetch();
    setReloading(false);
    setReloadFailed(result.isError);
    if (result.data && !result.isError) {
      setBase(result.data);
      save.reset();
    }
  }
  function move(index: number, step: number) {
    const next = [...ids];
    [next[index], next[index + step]] = [next[index + step]!, next[index]!];
    setIds(next);
    setDefaults(false);
  }
  const labels = new Map([
    ...selectedAccounts.map((account) => [account.accountId, account.label] as const),
    ...(accounts.data?.map((account) => [account.id, account.label] as const) ?? []),
  ]);
  const options =
    accounts.data ??
    Array.from({ length: 6 }, (_, index) => {
      const account = selectedAccounts[index];
      return account ? { ...account, id: account.accountId } : null;
    });
  return (
    <Modal close={close} title={t('budget.monthly.pinned.configure')}>
      <form
        className={styles.form}
        onSubmit={(event) => {
          event.preventDefault();
          save.mutate({ ...base, visibleAccountIds: defaults ? null : ids }, { onSuccess: close });
        }}
      >
        <p>{t('budget.monthly.pinned.hint')}</p>
        {error === 'conflict' ? (
          <div role="alert">
            <p>{t('budget.monthly.pinned.conflict')}</p>
            <button
              className="secondary-action"
              disabled={reloading}
              onClick={() => void reload()}
              type="button"
            >
              {t('budget.monthly.recap.preferences.reload')}
            </button>
          </div>
        ) : error ? (
          <p role="alert">{t(`budget.errors.${error}`)}</p>
        ) : null}
        {reloadFailed ? <p role="alert">{t('budget.monthly.recap.preferences.error')}</p> : null}
        <fieldset disabled={save.isPending || accounts.isPending || reloading}>
          <legend>{t('budget.monthly.pinned.order')}</legend>
          <ol className={styles.order}>
            {ids.map((id, index) => (
              <li key={id}>
                <span>{labels.get(id) ?? <SkeletonBlock className={styles.line} />}</span>
                <button
                  aria-label={t('budget.monthly.pinned.up', {
                    name: labels.get(id) ?? t('budget.monthly.pinned.account'),
                  })}
                  className="icon-button"
                  disabled={index === 0}
                  onClick={() => move(index, -1)}
                  type="button"
                >
                  <Icon name="arrow-up" size={16} />
                </button>
                <button
                  aria-label={t('budget.monthly.pinned.down', {
                    name: labels.get(id) ?? t('budget.monthly.pinned.account'),
                  })}
                  className="icon-button"
                  disabled={index === ids.length - 1}
                  onClick={() => move(index, 1)}
                  type="button"
                >
                  <Icon name="arrow-down" size={16} />
                </button>
              </li>
            ))}
          </ol>
        </fieldset>
        <fieldset disabled={save.isPending || accounts.isPending || reloading}>
          <legend>{t('budget.monthly.pinned.choose')}</legend>
          {accounts.isPending ? (
            <SkeletonStatus>{t('budget.monthly.pinned.loading')}</SkeletonStatus>
          ) : null}
          {accounts.isError ? (
            <div role="alert">
              <p>{t('budget.monthly.pinned.accountsError')}</p>
              <button
                className="secondary-action"
                onClick={() => void accounts.refetch()}
                type="button"
              >
                {t('foundation.retry')}
              </button>
            </div>
          ) : accounts.data?.length === 0 ? (
            <p>{t('budget.monthly.pinned.noAccounts')}</p>
          ) : (
            <div aria-busy={accounts.isPending} className={styles.options}>
              {options.map((account, index) => (
                <label key={account?.id ?? index}>
                  <input
                    checked={account ? ids.includes(account.id) : false}
                    disabled={
                      accounts.isPending ||
                      !account ||
                      (!ids.includes(account.id) && ids.length >= 6)
                    }
                    onChange={() => {
                      if (!account) return;
                      setDefaults(false);
                      setIds(
                        ids.includes(account.id)
                          ? ids.filter((id) => id !== account.id)
                          : [...ids, account.id],
                      );
                    }}
                    type="checkbox"
                  />
                  <span>
                    {account ? (
                      <>
                        {account.label} ({account.assetCode})
                        {account.status !== 'ACTIVE'
                          ? ` · ${t(`accounts.statuses.${account.status}`)}`
                          : ''}
                      </>
                    ) : (
                      <SkeletonBlock className={styles.line} />
                    )}
                  </span>
                </label>
              ))}
            </div>
          )}
        </fieldset>
        <button
          className="secondary-action"
          disabled={save.isPending || accounts.isPending || reloading}
          onClick={() => {
            setDefaults(true);
            setIds(selectedAccounts.map((a) => a.accountId));
          }}
          type="button"
        >
          {t('budget.monthly.pinned.defaults')}
        </button>
        {defaults ? <p role="status">{t('budget.monthly.pinned.defaultHint')}</p> : null}
        <div className={styles.actions}>
          <button className="secondary-action" onClick={close} type="button">
            {t('actions.cancel')}
          </button>
          <button
            className="primary-action"
            disabled={
              save.isPending ||
              accounts.isPending ||
              accounts.isError ||
              error === 'conflict' ||
              reloading
            }
            type="submit"
          >
            {t(
              save.isPending
                ? 'budget.monthly.recap.preferences.saving'
                : 'budget.monthly.recap.preferences.save',
            )}
          </button>
        </div>
      </form>
    </Modal>
  );
}
