import type {
  Account,
  AccountRuleClaim,
  AccountRuleOverride,
  AccountRules,
  ProductRuleKind,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { AccountFactRows } from '@/features/accounts/account-rules/account-sheet/AccountFactRows';
import { RuleActions } from '@/features/accounts/account-rules/account-sheet/RuleActions';
import { RuleRow } from '@/features/accounts/account-rules/account-sheet/RuleRow';
import { AlertLine } from '@/features/accounts/account-rules/rule-alert/RuleAlert';
import { RuleHistory } from '@/features/accounts/account-rules/rule-history/RuleHistory';
import {
  ceilingLine,
  rateLine,
  termLine,
  type RuleLine,
} from '@/features/accounts/account-rules/ruleLayers';
import { Sheet, SheetRow } from '@/features/accounts/account-rules/sheet-row/SheetRow';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { formatCalendarDay } from '@/lib/decimal';

interface AccountSheetProps {
  account: Account;
  /** The rule kind whose "Déroger" action regains focus after an override or withdrawal modal closes. */
  focusKind: ProductRuleKind | null;
  onOverride: (kind: ProductRuleKind) => void;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  /** Every claim ever recorded on the account, withdrawn ones included. */
  overrides: AccountRuleOverride[];
  rules: AccountRules;
}

/**
 * The account at a glance: opening, closing, yield, then one line per rule in
 * force on the business date. No value is computed here; the API resolves each
 * one with its period. A rule no layer covers keeps its line and says why it is
 * unavailable, because an empty value and a zero would both read as "no
 * ceiling" or "no interest".
 *
 * A contractual term this locale has no wording for says nothing a reader can
 * use, so it is left out rather than printed as its raw identifier.
 */
export function AccountSheet({
  account,
  focusKind,
  onOverride,
  onWithdraw,
  overrides,
  rules,
}: AccountSheetProps) {
  const { i18n, t } = useTranslation();
  const contractual = rules.yieldReading.contractual;
  const yieldRate = contractual
    ? rules.rates.find((rate) => rate.kind === contractual.kind)
    : undefined;

  function row(kind: ProductRuleKind, line: RuleLine | null, label?: string, valueLead?: string) {
    return line === null ? null : (
      <RuleRow
        focusAction={focusKind === kind}
        key={kind}
        kind={kind}
        label={label}
        line={line}
        onOverride={onOverride}
        onWithdraw={onWithdraw}
        overrides={overrides.filter((override) => override.kind === kind)}
        valueLead={valueLead}
      />
    );
  }

  return (
    <Sheet>
      <AccountFactRows account={account} />
      {yieldRate && contractual ? (
        row(
          yieldRate.kind,
          rateLine(yieldRate, rules.assetCode),
          t('accounts.rules.yield.title'),
          t(
            contractual.guaranteed
              ? 'accounts.rules.yield.guaranteed'
              : 'accounts.rules.yield.revisable',
          ),
        )
      ) : (
        <SheetRow
          label={t('accounts.rules.yield.title')}
          value={t('accounts.rules.yield.noContractual')}
        />
      )}
      {rules.ceilings.map((ceiling) => row(ceiling.kind, ceilingLine(ceiling, i18n.language)))}
      {rules.rates
        .filter((rate) => rate !== yieldRate)
        .map((rate) => row(rate.kind, rateLine(rate, rules.assetCode)))}
      {rules.terms.filter((term) => hasWording(term)).map((term) => row(term.kind, termLine(term)))}
      {rules.unavailableRuleKinds.map((kind) => (
        <SheetRow
          actions={
            <RuleActions
              focusAction={focusKind === kind}
              kind={kind}
              name={t(`catalog.rules.kinds.${kind}`)}
              onOverride={onOverride}
            />
          }
          alert={
            <AlertLine>
              {t('accounts.rules.unavailable', {
                date: formatCalendarDay(rules.asOf, i18n.language),
              })}
            </AlertLine>
          }
          below={
            <RuleHistory
              inForceId={null}
              kind={kind}
              onWithdraw={onWithdraw}
              overrides={overrides.filter((override) => override.kind === kind)}
            />
          }
          key={kind}
          label={t(`catalog.rules.kinds.${kind}`)}
          value={<EmptyValue label={t('accounts.rules.unavailableValue')} />}
        />
      ))}
    </Sheet>
  );
}

function hasWording(term: AccountRules['terms'][number]): boolean {
  const effective = { CATALOG: term.catalog, INHERITED: term.inherited, OVERRIDE: term.override }[
    term.effectiveLayer
  ];

  return effective === null || ruleTextKey(effective.token) !== null;
}
