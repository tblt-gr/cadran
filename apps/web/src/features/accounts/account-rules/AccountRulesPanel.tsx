import type { Account, AccountRules } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountRequestError } from '@/features/accounts/accountError';
import { AccountRuleTable } from '@/features/accounts/account-rules/account-rule-table/AccountRuleTable';
import { useAccountRules } from '@/features/accounts/account-rules/useAccountRules';
import { BusinessDateField } from '@/features/catalog/business-date-field/BusinessDateField';
import { todayInBrowser } from '@/lib/businessDay';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './AccountRulesPanel.module.css';

interface AccountRulesPanelProps {
  account: Account;
}

/**
 * The ceilings, rates and terms in force for one account, resolved on a business
 * date the reader chooses.
 *
 * The date is a deliberate control and not the day the panel happens to be
 * opened: a ceiling raised in April and a rate set for one semester are read by
 * the date of the operation being looked at. Nothing here computes a financial
 * figure; the API resolves every value with its period and its publication, and
 * a rule no period covers stays unavailable rather than becoming a zero.
 */
export function AccountRulesPanel({ account }: AccountRulesPanelProps) {
  const { i18n, t } = useTranslation();
  const today = todayInBrowser();
  const [asOf, setAsOf] = useState(today);
  const rules = useAccountRules(account.id, asOf);

  const unauthorized = rules.error instanceof AccountRequestError && rules.error.status === 401;

  return (
    <div className={styles.panel}>
      <BusinessDateField onChange={setAsOf} today={today} value={asOf} />

      {rules.isPending ? (
        <p aria-busy="true" className={styles.state} role="status">
          {t('accounts.rules.loading')}
        </p>
      ) : rules.isError ? (
        <div className={styles.state} role="alert">
          <p>{t(unauthorized ? 'accounts.rules.unauthorized' : 'accounts.rules.error')}</p>
          {!unauthorized ? (
            <button className="secondary-action" onClick={() => void rules.refetch()} type="button">
              {t('foundation.retry')}
            </button>
          ) : null}
        </div>
      ) : (
        <>
          <p className={styles.resolved} role="status">
            {t('accounts.rules.resolvedOn', {
              date: formatCalendarDay(rules.data.asOf, i18n.language),
            })}
          </p>

          {rules.data.origin === 'NO_PRODUCT' ? (
            <p className={styles.note}>{t('accounts.rules.origins.NO_PRODUCT')}</p>
          ) : rules.data.origin === 'PRODUCT_WITHDRAWN' ? (
            <p className={styles.warning} role="alert">
              {t('accounts.rules.origins.PRODUCT_WITHDRAWN', { product: rules.data.productCode })}
            </p>
          ) : null}

          {isEmpty(rules.data) ? (
            // A withdrawn product resolves nothing whatever the date, and the
            // alert above already says so. Adding "no rule sourced on this
            // date" beneath it would blame the date for an emptiness the date
            // has nothing to do with, and read as "this account has no
            // ceiling".
            rules.data.origin === 'PRODUCT_WITHDRAWN' ? null : (
              <p className={styles.note}>
                {t('accounts.rules.none', {
                  date: formatCalendarDay(rules.data.asOf, i18n.language),
                })}
              </p>
            )
          ) : (
            <>
              <AccountRuleTable rules={rules.data} />
              {rules.data.ceilings.length > 0 ? (
                // Passing a ceiling is not a fault the application acts on:
                // credited interest and a historical import both record what the
                // account really held, so a breach is reported and never refused.
                <p className={styles.note}>{t('accounts.rules.breachPolicy')}</p>
              ) : null}
            </>
          )}
        </>
      )}
    </div>
  );
}

/** Nothing was resolved at all: not one rule, and not one reported gap either. */
function isEmpty(rules: AccountRules): boolean {
  return (
    rules.ceilings.length === 0 &&
    rules.rates.length === 0 &&
    rules.terms.length === 0 &&
    rules.unavailableRuleKinds.length === 0
  );
}
