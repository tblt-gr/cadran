import type { Account, AccountRuleClaim, AccountRules, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountRequestError } from '@/features/accounts/accountError';
import { AccountSheet } from '@/features/accounts/account-rules/account-sheet/AccountSheet';
import { AccountRulesPanelSkeleton } from './AccountRulesPanelSkeleton';
import { useAccountRuleOverrides } from '@/features/accounts/account-rules/useAccountRuleOverrides';
import { useAccountRules } from '@/features/accounts/account-rules/useAccountRules';
import type { OverrideDraft } from '@/features/accounts/account-rules/overrideTarget';
import { todayInBrowser } from '@/lib/businessDay';
import { Icon } from '@/components/ui/icon/Icon';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './AccountRulesPanel.module.css';

interface AccountRulesPanelProps {
  account: Account;
  onOverride: (draft: OverrideDraft) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  /** The rule whose action regains focus when the reader returns from a modal. */
  focusKind?: ProductRuleKind | null;
}

/**
 * The ceilings, rates and terms in force today for one account, and every claim
 * the account has ever made on them.
 *
 * Nothing here computes a financial figure; the API resolves every value with
 * its period and its local claim, and a rule no layer covers stays unavailable
 * rather than becoming a zero. The sheet always answers for the reader's own
 * calendar day, the one the browser reports.
 */
export function AccountRulesPanel({
  account,
  focusKind = null,
  onOverride,
  onWithdraw,
}: AccountRulesPanelProps) {
  const { i18n, t } = useTranslation();
  const rules = useAccountRules(account.id, todayInBrowser());
  const overrides = useAccountRuleOverrides(account.id);

  const failure = rules.error instanceof AccountRequestError ? rules.error : null;
  const unauthorized = failure?.status === 401;

  if (rules.isPending || overrides.isPending) {
    return <AccountRulesPanelSkeleton account={account} status={t('accounts.rules.loading')} />;
  }

  return (
    <div className={styles.panel}>
      {rules.isError ? (
        <div className={styles.failure} role="alert">
          <p>{t(unauthorized ? 'accounts.rules.unauthorized' : 'accounts.rules.error')}</p>
          {!unauthorized ? (
            <button className="secondary-action" onClick={() => void rules.refetch()} type="button">
              {t('foundation.retry')}
            </button>
          ) : null}
        </div>
      ) : (
        <>
          {rules.data.origin === 'NO_PRODUCT' ? (
            <p className={styles.state}>
              <Icon name="info" size={14} />
              {t('accounts.rules.origins.NO_PRODUCT')}
            </p>
          ) : rules.data.origin === 'PRODUCT_WITHDRAWN' ? (
            <p className={styles.state} role="alert">
              <Icon name="alert" size={14} />
              {t('accounts.rules.origins.PRODUCT_WITHDRAWN', { product: rules.data.productCode })}
            </p>
          ) : null}

          <AccountSheet
            account={account}
            focusKind={focusKind}
            onOverride={(kind) => onOverride({ kind, kinds: statableKinds(rules.data) })}
            onWithdraw={onWithdraw}
            overrides={overrides.isError ? [] : overrides.data.overrides}
            rules={rules.data}
          />

          {isEmpty(rules.data) &&
          rules.data.origin !== 'NO_PRODUCT' &&
          rules.data.origin !== 'PRODUCT_WITHDRAWN' ? (
            <p className={styles.note}>
              {t('accounts.rules.none', {
                date: formatCalendarDay(rules.data.asOf, i18n.language),
              })}
            </p>
          ) : null}
        </>
      )}

      {overrides.isError ? (
        <p className={styles.state} role="alert">
          <Icon name="alert" size={14} />
          {t('accounts.overrides.historyError')}
        </p>
      ) : null}
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

/**
 * The rule kinds this account may state at all: the ones some authority
 * already answers for on this date, plus the ones it is expected to carry and
 * does not. Offering any other kind would present a choice the API refuses,
 * because an override may only state what the authority behind the account
 * could itself have stated.
 */
function statableKinds(rules: AccountRules): ProductRuleKind[] {
  return [
    ...rules.ceilings.map((ceiling) => ceiling.kind),
    ...rules.rates.map((rate) => rate.kind),
    ...rules.terms.map((term) => term.kind),
    ...rules.unavailableRuleKinds,
  ];
}
