import type { AccountCeiling, AccountCeilingRule, CeilingBasis } from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import styles from './CeilingMeasure.module.css';

/**
 * What the ceiling amount leaves out, stated per measure.
 *
 * The caveat cannot be derived from `countsCreditedInterest` alone: on a plan
 * capped on contributions that flag is false too, and credited interest is not
 * what carries such a plan past its ceiling — market gains are, and they are
 * not contributions at all. Naming the measure keeps each row's explanation
 * true of the figure it is checked against. A measure that leaves nothing out
 * answers null and shows no caveat.
 */
const outsideMeasureKeys = {
  NONE: null,
  TOTAL_BALANCE: null,
  BALANCE_EXCLUDING_INTEREST: 'accounts.rules.outsideMeasure.BALANCE_EXCLUDING_INTEREST',
  CONTRIBUTIONS: 'accounts.rules.outsideMeasure.CONTRIBUTIONS',
  COMBINED_CONTRIBUTIONS: 'accounts.rules.outsideMeasure.CONTRIBUTIONS',
} as const satisfies Record<CeilingBasis, ParseKeys | null>;

interface CeilingMeasureProps {
  accountAsset: string;
  ceiling: AccountCeiling;
  rule: AccountCeilingRule;
}

/**
 * The figure this ceiling is checked against, and what the amount alone would
 * not tell: what the measure leaves out, whether the allowance is shared with
 * another account, and whether the ceiling can be compared to this account at
 * all.
 */
export function CeilingMeasure({ accountAsset, ceiling, rule }: CeilingMeasureProps) {
  const { t } = useTranslation();
  const outside = outsideMeasureKeys[rule.basis];

  return (
    <>
      <span>{t(`accounts.rules.bases.${rule.basis}`)}</span>
      {outside === null ? null : <small>{t(outside)}</small>}
      {rule.spansSeveralAccounts ? <small>{t('accounts.rules.shared')}</small> : null}
      {ceiling.measurable ? null : (
        <small className={styles.notComparable}>
          {t('accounts.rules.notComparable', {
            account: accountAsset,
            ceiling: ceiling.amount.assetCode,
          })}
        </small>
      )}
    </>
  );
}
