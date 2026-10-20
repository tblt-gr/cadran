import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import itemStyles from './account-rule-item/AccountRuleItem.module.css';
import layerStyles from './account-rule-layer/AccountRuleLayer.module.css';
import listStyles from './account-rule-list/AccountRuleList.module.css';
import historyStyles from './override-history/OverrideHistory.module.css';
import yieldStyles from './yield-reading/YieldReading.module.css';
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
                  <button className="secondary-action" disabled type="button">
                    {t('accounts.rules.override')}
                  </button>
                </header>
                <ol className={itemStyles.layers}>
                  <li className={layerStyles.inForce}>
                    <dl className={layerStyles.reading}>
                      <div>
                        <dt>{t('accounts.rules.fields.origin')}</dt>
                        <dd>
                          <SkeletonBlock className={skeletonStyles.field} />
                        </dd>
                      </div>
                      <div>
                        <dt>{t('accounts.rules.fields.value')}</dt>
                        <dd className={layerStyles.value}>
                          <SkeletonBlock className={skeletonStyles.field} />
                        </dd>
                      </div>
                      <div>
                        <dt>{t('accounts.rules.fields.validity')}</dt>
                        <dd>
                          <SkeletonBlock className={skeletonStyles.field} />
                        </dd>
                      </div>
                    </dl>
                    <details className={layerStyles.details} open>
                      <summary>{t('accounts.rules.details')}</summary>
                      <dl className={layerStyles.reading}>
                        {(['measure', 'verification', 'source'] as const).map((field) => (
                          <div key={field}>
                            <dt>{t(`accounts.rules.fields.${field}`)}</dt>
                            <dd>
                              <SkeletonBlock className={skeletonStyles.field} />
                            </dd>
                          </div>
                        ))}
                      </dl>
                    </details>
                  </li>
                </ol>
              </section>
            </li>
          ))}
        </ul>
      </section>
      <section aria-labelledby="account-yield-reading-skeleton" className={yieldStyles.section}>
        <h3 id="account-yield-reading-skeleton">{t('accounts.rules.yield.title')}</h3>
        <p className={yieldStyles.hint}>{t('accounts.rules.yield.hint')}</p>
        <dl className={yieldStyles.readings}>
          {(['contractual', 'assumption', 'observed'] as const).map((reading) => (
            <div key={reading}>
              <dt>{t(`accounts.rules.yield.${reading}`)}</dt>
              <dd>
                <SkeletonBlock className={skeletonStyles.field} />
              </dd>
            </div>
          ))}
        </dl>
      </section>
      <details className={historyStyles.history}>
        <summary>{t('accounts.overrides.historyTitle')}</summary>
      </details>
    </div>
  );
}
