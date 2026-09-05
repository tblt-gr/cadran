import type { AccountRuleClaim, AccountRules, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountRuleRow } from '@/features/accounts/account-rules/account-rule-row/AccountRuleRow';
import {
  ceilingLayers,
  rateLayers,
  termLayers,
} from '@/features/accounts/account-rules/ruleLayers';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './AccountRuleTable.module.css';

interface AccountRuleTableProps {
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim) => void;
  rules: AccountRules;
}

/**
 * Everything in force for the account on the business date, with every
 * authority that states it shown beside the others.
 *
 * A ceiling is never shown as an amount alone: the measure it is checked
 * against decides whether a figure passes it, and a ceiling published in
 * another unit than the account decides nothing at all. A rule no layer covers
 * keeps its row and says the value is unknown, because an empty cell and a
 * zero would both read as "no ceiling" or "no interest".
 */
export function AccountRuleTable({ onOverride, onWithdraw, rules }: AccountRuleTableProps) {
  const { i18n, t } = useTranslation();

  return (
    <div className={styles.tableScroll}>
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
        {rules.ceilings.map((ceiling) => (
          <AccountRuleRow
            effectiveLayer={ceiling.effectiveLayer}
            key={ceiling.kind}
            kind={ceiling.kind}
            layers={ceilingLayers(ceiling, rules.assetCode, i18n.language)}
            onOverride={onOverride}
            onWithdraw={onWithdraw}
          />
        ))}
        {rules.rates.map((rate) => (
          <AccountRuleRow
            effectiveLayer={rate.effectiveLayer}
            key={rate.kind}
            kind={rate.kind}
            layers={rateLayers(rate, rules.assetCode)}
            onOverride={onOverride}
            onWithdraw={onWithdraw}
          />
        ))}
        {rules.terms.map((term) => (
          <AccountRuleRow
            effectiveLayer={term.effectiveLayer}
            key={term.kind}
            kind={term.kind}
            layers={termLayers(term)}
            onOverride={onOverride}
            onWithdraw={onWithdraw}
          />
        ))}
        {rules.unavailableRuleKinds.map((kind) => (
          <tbody key={kind}>
            <tr>
              <th scope="row">
                {t(`catalog.rules.kinds.${kind}`)}
                <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
                  {t('accounts.rules.override')}
                </button>
              </th>
              {/* Not a zero and not an empty cell: the value is unknown for this
                  date, and the reason travels with the dash. */}
              <td className={styles.unavailable} colSpan={6}>
                {t('catalog.rules.unavailable', {
                  date: formatCalendarDay(rules.asOf, i18n.language),
                })}
              </td>
            </tr>
          </tbody>
        ))}
      </table>
    </div>
  );
}
