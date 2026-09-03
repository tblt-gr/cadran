import type {
  AccountCeiling,
  AccountRules,
  CeilingBasis,
  ProductRuleKind,
} from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { RateBrackets } from '@/features/accounts/account-rules/rate-brackets/RateBrackets';
import { RuleProvenance } from '@/features/catalog/rule-provenance/RuleProvenance';
import { ruleTextKey } from '@/features/catalog/ruleText';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './AccountRuleTable.module.css';

interface AccountRuleTableProps {
  rules: AccountRules;
}

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

/**
 * Everything in force for the account on the business date, in one table.
 *
 * A ceiling is never shown as an amount alone: the measure it is checked
 * against decides whether a figure passes it, and a ceiling published in
 * another unit than the account decides nothing at all. A rule no sourced period
 * covers keeps its row and says the value is unknown, because an empty cell and
 * a zero would both read as "no ceiling" or "no interest".
 */
export function AccountRuleTable({ rules }: AccountRuleTableProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.tableScroll}>
      <table>
        <caption className="sr-only">{t('accounts.rules.caption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('accounts.rules.columns.rule')}</th>
            <th scope="col">{t('accounts.rules.columns.value')}</th>
            <th scope="col">{t('accounts.rules.columns.measure')}</th>
            <th scope="col">{t('accounts.rules.columns.period')}</th>
            <th scope="col">{t('accounts.rules.columns.verification')}</th>
            <th scope="col">{t('accounts.rules.columns.source')}</th>
          </tr>
        </thead>
        <tbody>
          {rules.ceilings.map((ceiling) => (
            <tr key={ceiling.kind}>
              <th scope="row">{t(`catalog.rules.kinds.${ceiling.kind}`)}</th>
              <td className={styles.value}>
                {formatAmount(ceiling.amount.value, ceiling.amount.assetCode, i18n.language)}
              </td>
              <td>
                <span>{t(`accounts.rules.bases.${ceiling.basis}`)}</span>
                <CeilingNotes assetCode={rules.assetCode} ceiling={ceiling} />
              </td>
              <RuleProvenance
                source={ceiling.source}
                validFrom={ceiling.validFrom}
                validTo={ceiling.validTo}
                verification={ceiling.verification}
              />
            </tr>
          ))}
          {rules.rates.map((rate) => (
            <tr key={rate.kind}>
              <th scope="row">{t(`catalog.rules.kinds.${rate.kind}`)}</th>
              <td>
                <RateBrackets assetCode={rules.assetCode} rate={rate} />
              </td>
              <td>
                <span>{t(`accounts.rules.applications.${rate.application}`)}</span>
                <small>
                  {t(rate.guaranteed ? 'accounts.rules.guaranteed' : 'accounts.rules.revisable')}
                </small>
              </td>
              <RuleProvenance
                source={rate.source}
                validFrom={rate.validFrom}
                validTo={rate.validTo}
                verification={rate.verification}
              />
            </tr>
          ))}
          {rules.terms.map((term) => {
            const wording = ruleTextKey(term.token);

            return (
              <tr key={term.kind}>
                <th scope="row">{t(`catalog.rules.kinds.${term.kind}`)}</th>
                <td>{wording === null ? term.token : t(wording)}</td>
                <td className={styles.notApplicable}>{t('accounts.rules.noMeasure')}</td>
                <RuleProvenance
                  source={term.source}
                  validFrom={term.validFrom}
                  validTo={term.validTo}
                  verification={term.verification}
                />
              </tr>
            );
          })}
          {rules.unavailableRuleKinds.map((kind: ProductRuleKind) => (
            <tr key={kind}>
              <th scope="row">{t(`catalog.rules.kinds.${kind}`)}</th>
              {/* Not a zero and not an empty cell: the value is unknown for this
                  date, and the reason travels with the dash. */}
              <td className={styles.unavailable} colSpan={5}>
                {t('catalog.rules.unavailable', {
                  date: formatCalendarDay(rules.asOf, i18n.language),
                })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

interface CeilingNotesProps {
  assetCode: string;
  ceiling: AccountCeiling;
}

/**
 * What the amount alone would not tell: what the measure leaves out, whether
 * the allowance is shared with another account, and whether the ceiling can be
 * compared to this account at all.
 */
function CeilingNotes({ assetCode, ceiling }: CeilingNotesProps) {
  const { t } = useTranslation();
  const outside = outsideMeasureKeys[ceiling.basis];

  return (
    <>
      {outside === null ? null : <small>{t(outside)}</small>}
      {ceiling.spansSeveralAccounts ? <small>{t('accounts.rules.shared')}</small> : null}
      {!ceiling.measurable ? (
        <small className={styles.notComparable}>
          {t('accounts.rules.notComparable', {
            account: assetCode,
            ceiling: ceiling.amount.assetCode,
          })}
        </small>
      ) : null}
    </>
  );
}
