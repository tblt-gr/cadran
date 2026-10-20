import type {
  AccountCeilingRule,
  AccountRateRule,
  AccountRuleClaim,
  AccountRuleLayer,
  AccountTermRule,
  RuleValidFrom,
  RuleValidTo,
} from '@cadran/api-client';
import type { ReactNode } from 'react';
import { RateBrackets } from '@/features/accounts/account-rules/rate-brackets/RateBrackets';
import { RuleAlert } from '@/features/accounts/account-rules/rule-alert/RuleAlert';
import { TermWording } from '@/features/accounts/account-rules/term-wording/TermWording';
import { formatAmount } from '@/lib/decimal';

/**
 * What the sheet reads of one rule: the value of the layer in force, since
 * when, and the short warning that changes how the figure is read. The other
 * authorities, the measure and the sources are the product catalogue's to show.
 */
export interface RuleLine {
  alert: ReactNode;
  /** The local claim when the value in force is the account's own: what a withdrawal needs. */
  claim: AccountRuleClaim | null;
  validFrom: RuleValidFrom;
  validTo: RuleValidTo;
  value: ReactNode;
}

interface LayeredRule<T> {
  catalog: T | null;
  effectiveLayer: AccountRuleLayer;
  inherited: T | null;
  override: T | null;
}

interface Carrier {
  claim: AccountRuleClaim | null;
  validFrom: RuleValidFrom;
  validTo: RuleValidTo;
}

/** The answer of the layer the API named as in force, or null if it carries none. */
function inForce<T>(rule: LayeredRule<T>): T | null {
  return { CATALOG: rule.catalog, INHERITED: rule.inherited, OVERRIDE: rule.override }[
    rule.effectiveLayer
  ];
}

function line(carrier: Carrier, value: ReactNode, alert: ReactNode): RuleLine {
  return {
    alert,
    claim: carrier.claim,
    validFrom: carrier.validFrom,
    validTo: carrier.validTo,
    value,
  };
}

export function ceilingLine(rule: AccountCeilingRule, language: string): RuleLine | null {
  const ceiling = inForce(rule);
  if (ceiling === null) {
    return null;
  }

  return line(
    ceiling,
    formatAmount(ceiling.amount.value, ceiling.amount.assetCode, language),
    <RuleAlert check={rule.check ?? null} comparable={ceiling.measurable} language={language} />,
  );
}

export function rateLine(rule: AccountRateRule, accountAsset: string): RuleLine | null {
  const rate = inForce(rule);
  if (rate === null) {
    return null;
  }

  return line(
    rate,
    <RateBrackets assetCode={accountAsset} rate={rate} />,
    rule.applied?.rateShiftsAboveFirstBracket ? <RuleAlert shifted /> : null,
  );
}

export function termLine(rule: AccountTermRule): RuleLine | null {
  const term = inForce(rule);

  return term === null ? null : line(term, <TermWording token={term.token} />, null);
}
