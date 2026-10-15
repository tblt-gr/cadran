import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
  SkeletonBlock,
  SkeletonStatus,
  SkeletonTableRows,
} from '@/components/ui/skeleton/Skeleton';
import tableStyles from './account-rule-table/AccountRuleTable.module.css';
import historyStyles from './override-history/OverrideHistory.module.css';
import styles from './AccountRulesPanel.module.css';
import skeletonStyles from './AccountRulesPanelSkeleton.module.css';

interface AccountRulesPanelSkeletonProps {
  /** The panel's own date field, live while the rules resolve. */
  dateField: ReactNode;
  status: string;
}

/** Mirrors AccountRulesPanel element for element: only resolved rules and claims are pending. */
export function AccountRulesPanelSkeleton({ dateField, status }: AccountRulesPanelSkeletonProps) {
  const { t } = useTranslation();

  return (
    <div aria-busy="true" className={styles.panel} data-testid="account-rules-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      {dateField}
      <SkeletonBlock className={skeletonStyles.resolved} />
      <div className={tableStyles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.rules.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.rules.columns.rule')}</th>
              <th scope="col">{t('accounts.rules.columns.origin')}</th>
              <th scope="col">{t('accounts.rules.columns.value')}</th>
              <th scope="col">{t('accounts.rules.columns.measure')}</th>
              <th scope="col">{t('accounts.rules.columns.period')}</th>
              <th scope="col">{t('accounts.rules.columns.verification')}</th>
              <th scope="col">{t('accounts.rules.columns.source')}</th>
            </tr>
          </thead>
          <SkeletonTableRows columns={7} rows={3} />
        </table>
      </div>
      <section className={styles.history} aria-labelledby="account-override-history-skeleton">
        <h3 id="account-override-history-skeleton">{t('accounts.overrides.historyTitle')}</h3>
        <p className={styles.note}>{t('accounts.overrides.historyHint')}</p>
        <div className={historyStyles.tableScroll}>
          <table>
            <caption className="sr-only">{t('accounts.overrides.historyCaption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('accounts.rules.columns.rule')}</th>
                <th scope="col">{t('accounts.rules.columns.period')}</th>
                <th scope="col">{t('accounts.overrides.columns.status')}</th>
                <th scope="col">{t('accounts.overrides.columns.author')}</th>
                <th scope="col">{t('accounts.overrides.columns.reason')}</th>
              </tr>
            </thead>
            <SkeletonTableRows columns={5} rows={2} />
          </table>
        </div>
      </section>
    </div>
  );
}
