import type {
  AccountCeiling,
  AccountCeilingRule,
  CeilingBasis,
  CeilingCheck,
} from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { formatAmount } from '@/lib/decimal';
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
  check: CeilingCheck | null;
  rule: AccountCeilingRule;
}

/**
 * The figure this ceiling is checked against, and what the amount alone would
 * not tell: what the measure leaves out, whether the allowance is shared with
 * another account, whether the ceiling can be compared to this account, and —
 * when a valuation exists — whether the measured figure sits above it.
 *
 * EXCEEDED is a warning, never a refusal. A Livret Bleu, credited interest or
 * an imported history may sit above the published amount; the scale, not this
 * status, decides what the excess earns.
 */
export function CeilingMeasure({ accountAsset, ceiling, check, rule }: CeilingMeasureProps) {
  const { i18n, t } = useTranslation();
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
      {check == null ? null : <CeilingCheckReading check={check} language={i18n.language} />}
    </>
  );
}

function CeilingCheckReading({ check, language }: { check: CeilingCheck; language: string }) {
  const { t } = useTranslation();

  if (check.status === 'UNSETTLED') {
    return (
      <small>
        {t('accounts.rules.check.UNSETTLED')}
        {check.unsettledReason === null
          ? null
          : ` ${t(`accounts.rules.unsettled.${check.unsettledReason}`)}`}
      </small>
    );
  }

  if (check.status === 'NOT_COMPARABLE') {
    return (
      <small className={styles.notComparable}>{t('accounts.rules.check.NOT_COMPARABLE')}</small>
    );
  }

  const measured =
    check.measured === null
      ? null
      : formatAmount(check.measured.value, check.measured.assetCode, language);
  const excess =
    check.excess === null
      ? null
      : formatAmount(check.excess.value, check.excess.assetCode, language);

  if (check.status === 'EXCEEDED') {
    return (
      <small className={styles.warning} role="status">
        {t('accounts.rules.check.EXCEEDED', { excess: excess ?? '—' })}
      </small>
    );
  }

  return <small>{t('accounts.rules.check.WITHIN', { measured: measured ?? '—' })}</small>;
}
