import type { Account } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountKeyFacts } from './account-key-facts/AccountKeyFacts';
import itemStyles from './account-rule-item/AccountRuleItem.module.css';
import listStyles from './account-rule-list/AccountRuleList.module.css';
import historyStyles from './override-history/OverrideHistory.module.css';
import yieldStyles from './yield-reading/YieldReading.module.css';
import styles from './AccountRulesPanel.module.css';
import skeletonStyles from './AccountRulesPanelSkeleton.module.css';

interface AccountRulesPanelSkeletonProps {
  account: Account;
  /** The panel's own date field, live while the rules resolve. */
  dateField: ReactNode;
  status: string;
}

/** Mirrors AccountRulesPanel element for element: only resolved rules and claims are pending. */
export function AccountRulesPanelSkeleton({
  account,
  dateField,
  status,
}: AccountRulesPanelSkeletonProps) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.panel} data-testid="account-rules-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      {dateField}
      <AccountKeyFacts account={account} />
      {dateField}
      <p className={styles.resolved}>
        <SkeletonBlock className={skeletonStyles.resolved} />
      </p>
      <section aria-labelledby="account-rules-list-skeleton" className={listStyles.section}>
        <h3 id="account-rules-list-skeleton">{t('accounts.rules.listTitle')}</h3>
        <ul className={listStyles.rules}>
          {[0, 1].map((index) => (
            <li key={index}>
              <section className={itemStyles.rule}>
                <header className={itemStyles.heading}>
                  <h4>
                    <SkeletonBlock className={skeletonStyles.ruleName} />
                  </h4>
                  <InfoButton disabled label={t('accounts.rules.details')} />
                </header>
                <p className={itemStyles.value}>
                  <SkeletonBlock className={skeletonStyles.field} />
                </p>
                <p className={itemStyles.period}>
                  <SkeletonBlock className={skeletonStyles.field} />
                </p>
              </section>
            </li>
          ))}
        </ul>
      </section>
      <section aria-labelledby="account-yield-reading-skeleton" className={yieldStyles.section}>
        <header className={yieldStyles.heading}>
          <h3 id="account-yield-reading-skeleton">{t('accounts.rules.yield.title')}</h3>
          <InfoButton disabled label={t('accounts.rules.yield.detailsLabel')} />
        </header>
        <p className={yieldStyles.reading}>
          <SkeletonBlock className={skeletonStyles.field} />
        </p>
      </section>
      <details className={historyStyles.history}>
        <summary>{t('accounts.overrides.historyTitle')}</summary>
      </details>
    </div>
  );
}
