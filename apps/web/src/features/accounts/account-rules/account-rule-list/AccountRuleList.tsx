import type { AccountRuleClaim, AccountRules, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AccountRuleItem } from '@/features/accounts/account-rules/account-rule-item/AccountRuleItem';
import { UnavailableRuleItem } from '@/features/accounts/account-rules/unavailable-rule-item/UnavailableRuleItem';
import {
  ceilingLayers,
  rateLayers,
  termLayers,
} from '@/features/accounts/account-rules/ruleLayers';
import styles from './AccountRuleList.module.css';

interface AccountRuleListProps {
  /** The rule kind whose action regains focus after an override or withdrawal modal closes. */
  focusKind: ProductRuleKind | null;
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  rules: AccountRules;
}

/**
 * Everything in force for the account on the business date, with every
 * authority that states it shown beside the others.
 *
 * A ceiling is never shown as an amount alone: the measure it is checked
 * against decides whether a figure passes it, and a ceiling published in
 * another unit than the account decides nothing at all. A rule no layer covers
 * keeps its place and says the value is unknown, because an empty value and a
 * zero would both read as "no ceiling" or "no interest".
 */
export function AccountRuleList({
  focusKind,
  onOverride,
  onWithdraw,
  rules,
}: AccountRuleListProps) {
  const { i18n, t } = useTranslation();
  const common = { onOverride, onWithdraw };

  return (
    <section aria-labelledby="account-rules-list" className={styles.section}>
      <h3 id="account-rules-list">{t('accounts.rules.listTitle')}</h3>
      <ul className={styles.rules}>
        {rules.ceilings.map((ceiling) => (
          <AccountRuleItem
            {...common}
            effectiveLayer={ceiling.effectiveLayer}
            focusAction={focusKind === ceiling.kind}
            key={ceiling.kind}
            kind={ceiling.kind}
            layers={ceilingLayers(ceiling, rules.assetCode, i18n.language)}
          />
        ))}
        {rules.rates.map((rate) => (
          <AccountRuleItem
            {...common}
            effectiveLayer={rate.effectiveLayer}
            focusAction={focusKind === rate.kind}
            key={rate.kind}
            kind={rate.kind}
            layers={rateLayers(rate, rules.assetCode)}
          />
        ))}
        {rules.terms.map((term) => (
          <AccountRuleItem
            {...common}
            effectiveLayer={term.effectiveLayer}
            focusAction={focusKind === term.kind}
            key={term.kind}
            kind={term.kind}
            layers={termLayers(term)}
          />
        ))}
        {rules.unavailableRuleKinds.map((kind) => (
          <UnavailableRuleItem
            asOf={rules.asOf}
            focusAction={focusKind === kind}
            key={kind}
            kind={kind}
            onOverride={onOverride}
          />
        ))}
      </ul>
    </section>
  );
}
