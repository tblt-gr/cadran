import type { Account, AccountRuleClaim, AccountRules, ProductRuleKind } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AccountRequestError } from '@/features/accounts/accountError';
import { AccountSheet } from '@/features/accounts/account-rules/account-sheet/AccountSheet';
import { AccountRulesPanelSkeleton } from './AccountRulesPanelSkeleton';
import { OverrideHistory } from '@/features/accounts/account-rules/override-history/OverrideHistory';
import { useAccountRuleOverrides } from '@/features/accounts/account-rules/useAccountRuleOverrides';
import { useAccountRules } from '@/features/accounts/account-rules/useAccountRules';
import type { OverrideDraft } from '@/features/accounts/account-rules/overrideTarget';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
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
 * The ceilings, rates and terms in force for one account, resolved on a
 * business date the reader chooses, and every claim the account has ever made.
 *
 * The date is a deliberate control and not the day the panel happens to be
 * opened: a ceiling raised in April and a rate set for one semester are read by
 * the date of the operation being looked at. Nothing here computes a financial
 * figure; the API resolves every value with its period, its publication and its
 * local claim, and a rule no layer covers stays unavailable rather than
 * becoming a zero.
 */
export function AccountRulesPanel({
  account,
  focusKind = null,
  onOverride,
  onWithdraw,
}: AccountRulesPanelProps) {
  const { i18n, t } = useTranslation();
  // The reader's own calendar day, not the server's: the field they are about
  // to move is theirs, and every answer echoes the date it was resolved for, so
  // the two never disagree silently.
  const today = todayInBrowser();
  const [asOf, setAsOf] = useState(today);
  const rules = useAccountRules(account.id, asOf);
  const overrides = useAccountRuleOverrides(account.id);

  const failure = rules.error instanceof AccountRequestError ? rules.error : null;
  const unauthorized = failure?.status === 401;
  // The date field bounds are advisory outside a form, so a year typed
  // digit by digit reaches the API. Repeating a request the API already
  // refused would fail identically, so this one is stated, not retried.
  const outOfRange = failure?.kind === 'invalid';
  const retryable = !unauthorized && !outOfRange;

  const dateField = (
    <BusinessDateField
      hint={null}
      label={t('accounts.rules.asOf')}
      onChange={setAsOf}
      today={today}
      value={asOf}
    />
  );

  if (rules.isPending || overrides.isPending) {
    return (
      <AccountRulesPanelSkeleton
        account={account}
        dateField={dateField}
        status={t('accounts.rules.loading')}
      />
    );
  }

  return (
    <div className={styles.panel}>
      {dateField}

      {rules.isPlaceholderData && !rules.isError ? (
        // The list below still answers the previous date until the new one
        // resolves; saying so is what keeps it from being read as the answer to
        // the date now in the field. A refused date is stated by the block
        // below instead: nothing is being resolved any more.
        <p aria-busy="true" className={styles.state} role="status">
          {t('accounts.rules.resolving', { date: formatCalendarDay(asOf, i18n.language) })}
        </p>
      ) : null}

      {rules.isError ? (
        <div className={styles.failure} role="alert">
          <p>
            {t(
              unauthorized
                ? 'accounts.rules.unauthorized'
                : outOfRange
                  ? 'accounts.rules.outOfRange'
                  : 'accounts.rules.error',
            )}
          </p>
          {retryable ? (
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
        <p className={styles.note} role="alert">
          {t('accounts.overrides.historyError')}
        </p>
      ) : (
        <OverrideHistory overrides={overrides.data.overrides} />
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
