import type { MonthlySelectedAccount } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { useSession } from '@/features/auth/useSession';
import { budgetErrorKind } from '@/features/budget/budgetError';
import {
  useMonthlyRecap,
  useRecapPreferences,
} from '@/features/budget/monthly-budget/monthly-recap/useMonthlyRecap';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import { PinnedAccountsModal } from './PinnedAccountsModal';
import styles from './PinnedAccountBalances.module.css';

interface ViewProps {
  accounts: MonthlySelectedAccount[] | null | undefined;
  month: string;
  onConfigure?: () => void;
  children?: React.ReactNode;
  announce?: boolean;
}
export function PinnedAccountBalancesView({
  accounts,
  month,
  onConfigure,
  children,
  announce = true,
}: ViewProps) {
  const { t, i18n } = useTranslation();
  const rows = accounts === undefined ? [] : (accounts ?? Array.from({ length: 6 }, () => null));
  return (
    <section
      aria-labelledby="pinned-balances-title"
      className={`card ${styles.panel}`}
      aria-busy={accounts === null}
    >
      <header className={styles.heading}>
        <h3 id="pinned-balances-title">{t('budget.monthly.pinned.title')}</h3>
        <button
          className="secondary-action"
          disabled={!onConfigure}
          onClick={onConfigure}
          type="button"
        >
          {t('budget.monthly.pinned.configure')}
        </button>
      </header>
      {accounts === null && announce ? (
        <SkeletonStatus>{t('budget.monthly.pinned.loading')}</SkeletonStatus>
      ) : null}
      {children}
      {accounts === undefined ? null : rows.length === 0 ? (
        <p className={styles.meta}>{t('budget.monthly.pinned.empty')}</p>
      ) : (
        <ul className={styles.rows}>
          {rows.map((account, index) => {
            const href = account ? `/accounts/${account.accountId}?month=${month}` : undefined;
            return (
              <li className={styles.row} key={account?.accountId ?? index}>
                <div className={styles.identity}>
                  <a
                    aria-disabled={!account}
                    href={href}
                    onClick={(event) => {
                      if (href) handleClientNavigation(event, href);
                    }}
                  >
                    {account ? account.label : <SkeletonBlock className={styles.line} />}
                  </a>
                  <small>
                    {account ? account.assetCode : <SkeletonBlock className={styles.line} />}
                  </small>
                  {account?.status !== 'ACTIVE' && account ? (
                    <small>{t(`accounts.statuses.${account.status}`)}</small>
                  ) : null}
                </div>
                <div className={styles.balance}>
                  <strong>
                    {account ? (
                      account.belowDisplayStep ? (
                        t('accounts.balances.belowStep')
                      ) : account.displayValue === null ? (
                        t('accounts.balances.missing')
                      ) : (
                        <MoneyValue
                          value={formatAmount(
                            account.displayValue,
                            account.assetCode,
                            i18n.language,
                          )}
                        />
                      )
                    ) : (
                      <MoneyValue value={null} skeletonClassName={styles.line} />
                    )}
                  </strong>
                  <small>
                    {account ? (
                      t('budget.monthly.pinned.requestedOn', {
                        date: formatCalendarDay(account.requestedOn, i18n.language),
                      })
                    ) : (
                      <SkeletonBlock className={styles.line} />
                    )}
                  </small>
                  <small>
                    {account ? (
                      account.reason ? (
                        t(
                          account.reason === 'NOT_YET_OPENED'
                            ? 'budget.monthly.recap.accounts.notOpen'
                            : account.reason === 'MIXED_ASSETS'
                              ? 'accounts.balances.mixedAssets'
                              : 'budget.monthly.recap.accounts.missingValuation',
                        )
                      ) : (
                        t(`accounts.balances.qualities.${account.quality}`)
                      )
                    ) : (
                      <SkeletonBlock className={styles.line} />
                    )}
                  </small>
                  <small>
                    {account ? (
                      [
                        account.valuedOn
                          ? formatCalendarDay(account.valuedOn, i18n.language)
                          : null,
                        account.basis === 'SNAPSHOT_PLUS_MOVEMENTS'
                          ? t('budget.monthly.pinned.derived')
                          : account.source
                            ? t(`accounts.balances.sources.${account.source}`)
                            : null,
                        account.quality === 'STALE' && account.ageDays !== null
                          ? t('accounts.balances.age', { count: account.ageDays })
                          : null,
                      ]
                        .filter(Boolean)
                        .join(' · ')
                    ) : (
                      <SkeletonBlock className={styles.line} />
                    )}
                  </small>
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
export function PinnedAccountBalances({ month }: { month: string }) {
  const { t } = useTranslation();
  const recap = useMonthlyRecap(month);
  const preferences = useRecapPreferences();
  const session = useSession();
  const [editing, setEditing] = useState(false);
  const error = budgetErrorKind(recap.error, recap.isError);
  return (
    <>
      <PinnedAccountBalancesView
        accounts={recap.data?.selectedAccounts ?? (recap.isPending ? null : undefined)}
        month={month}
        onConfigure={
          session.data?.workspace?.role === 'OWNER' && preferences.data && recap.data
            ? () => setEditing(true)
            : undefined
        }
      >
        {error ? (
          <div role="alert">
            <p>{t(`budget.errors.${error}`)}</p>
            <button className="secondary-action" onClick={() => void recap.refetch()} type="button">
              {t('foundation.retry')}
            </button>
          </div>
        ) : null}
        {preferences.isError ? (
          <div role="alert">
            <p>{t('budget.monthly.recap.preferences.error')}</p>
            <button
              className="secondary-action"
              onClick={() => void preferences.refetch()}
              type="button"
            >
              {t('foundation.retry')}
            </button>
          </div>
        ) : null}
      </PinnedAccountBalancesView>
      {editing && preferences.data ? (
        <PinnedAccountsModal
          close={() => setEditing(false)}
          preferences={preferences.data}
          selectedAccounts={recap.data?.selectedAccounts ?? []}
        />
      ) : null}
    </>
  );
}
