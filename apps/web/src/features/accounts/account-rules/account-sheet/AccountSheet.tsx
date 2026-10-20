import type { Account, AccountRuleClaim, AccountRules, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { AlertLine } from '@/features/accounts/account-rules/rule-alert/RuleAlert';
import { AccountFactRows } from '@/features/accounts/account-rules/account-sheet/AccountFactRows';
import { RuleRow } from '@/features/accounts/account-rules/account-sheet/RuleRow';
import { YieldDetails } from '@/features/accounts/account-rules/account-sheet/YieldDetails';
import { Sheet, SheetRow } from '@/features/accounts/account-rules/sheet-row/SheetRow';
import {
  ceilingLayers,
  rateLayers,
  termLayers,
} from '@/features/accounts/account-rules/ruleLayers';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './AccountSheet.module.css';

interface AccountSheetProps {
  account: Account;
  /** The rule kind whose "i" button regains focus after an override or withdrawal modal closes. */
  focusKind: ProductRuleKind | null;
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  rules: AccountRules;
}

/**
 * The account at a glance: opening, closing, yield, then one line per rule in
 * force on the business date. No value is computed here; the API resolves each
 * one with its period and its layers. A rule no layer covers keeps its line and
 * says why it is unavailable, because an empty value and a zero would both read
 * as "no ceiling" or "no interest".
 *
 * A contractual term this locale has no wording for is not worth a line on the
 * main path: those are gathered under one closed "other conditions" disclosure.
 */
export function AccountSheet({
  account,
  focusKind,
  onOverride,
  onWithdraw,
  rules,
}: AccountSheetProps) {
  const { i18n, t } = useTranslation();
  const common = { onOverride, onWithdraw };
  const contractual = rules.yieldReading.contractual;
  const yieldRate = contractual
    ? rules.rates.find((rate) => rate.kind === contractual.kind)
    : undefined;
  const readTerms = rules.terms.filter((term) => hasWording(term));
  const otherTerms = rules.terms.filter((term) => !hasWording(term));

  return (
    <>
      <Sheet>
        <AccountFactRows account={account} />
        {yieldRate && contractual ? (
          <RuleRow
            {...common}
            effectiveLayer={yieldRate.effectiveLayer}
            extraDetail={<YieldDetails />}
            focusAction={focusKind === yieldRate.kind}
            kind={yieldRate.kind}
            label={t('accounts.rules.yield.title')}
            layers={rateLayers(yieldRate, rules.assetCode)}
            valueLead={t(
              contractual.guaranteed
                ? 'accounts.rules.yield.guaranteed'
                : 'accounts.rules.yield.revisable',
            )}
          />
        ) : (
          <SheetRow
            detail={<YieldDetails />}
            detailLabel={t('accounts.rules.yield.detailsLabel')}
            label={t('accounts.rules.yield.title')}
            value={t('accounts.rules.yield.noContractual')}
          />
        )}
        {rules.ceilings.map((ceiling) => (
          <RuleRow
            {...common}
            effectiveLayer={ceiling.effectiveLayer}
            focusAction={focusKind === ceiling.kind}
            key={ceiling.kind}
            kind={ceiling.kind}
            layers={ceilingLayers(ceiling, rules.assetCode, i18n.language)}
          />
        ))}
        {rules.rates
          .filter((rate) => rate !== yieldRate)
          .map((rate) => (
            <RuleRow
              {...common}
              effectiveLayer={rate.effectiveLayer}
              focusAction={focusKind === rate.kind}
              key={rate.kind}
              kind={rate.kind}
              layers={rateLayers(rate, rules.assetCode)}
            />
          ))}
        {readTerms.map((term) => (
          <RuleRow
            {...common}
            effectiveLayer={term.effectiveLayer}
            focusAction={focusKind === term.kind}
            key={term.kind}
            kind={term.kind}
            layers={termLayers(term)}
          />
        ))}
        {rules.unavailableRuleKinds.map((kind) => (
          <SheetRow
            alert={
              <AlertLine>
                {t('accounts.rules.unavailable', {
                  date: formatCalendarDay(rules.asOf, i18n.language),
                })}
              </AlertLine>
            }
            detail={
              <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
                {t('accounts.rules.override')}
              </button>
            }
            detailLabel={t('accounts.rules.detailsOf', { rule: t(`catalog.rules.kinds.${kind}`) })}
            focusAction={focusKind === kind}
            key={kind}
            label={t(`catalog.rules.kinds.${kind}`)}
            value={<EmptyValue label={t('accounts.rules.unavailableValue')} />}
          />
        ))}
      </Sheet>
      {otherTerms.length === 0 ? null : (
        <details className={styles.others}>
          <summary>{t('accounts.rules.otherConditions', { count: otherTerms.length })}</summary>
          <Sheet>
            {otherTerms.map((term) => (
              <RuleRow
                {...common}
                effectiveLayer={term.effectiveLayer}
                focusAction={focusKind === term.kind}
                key={term.kind}
                kind={term.kind}
                layers={termLayers(term)}
              />
            ))}
          </Sheet>
        </details>
      )}
    </>
  );
}

function hasWording(term: AccountRules['terms'][number]): boolean {
  const effective = { CATALOG: term.catalog, INHERITED: term.inherited, OVERRIDE: term.override }[
    term.effectiveLayer
  ];

  return effective === null || ruleTextKey(effective.token) !== null;
}
