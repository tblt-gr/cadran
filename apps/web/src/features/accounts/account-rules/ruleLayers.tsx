import type {
  AccountCeiling,
  AccountCeilingRule,
  AccountRate,
  AccountRateRule,
  AccountRuleClaim,
  AccountRuleLayer,
  AccountTerm,
  AccountTermRule,
  ProductRuleSource,
  RuleVerification,
} from '@cadran/api-client';
import type { ReactNode } from 'react';
import { CeilingMeasure } from '@/features/accounts/account-rules/ceiling-measure/CeilingMeasure';
import { RateBrackets } from '@/features/accounts/account-rules/rate-brackets/RateBrackets';
import { RateMeasure } from '@/features/accounts/account-rules/rate-measure/RateMeasure';
import { TermMeasure } from '@/features/accounts/account-rules/term-measure/TermMeasure';
import { TermWording } from '@/features/accounts/account-rules/term-wording/TermWording';
import { formatAmount } from '@/lib/decimal';

/**
 * One authority's answer for one rule, flattened into what a table row needs.
 *
 * The three rule families produce different cells — an amount and its measure,
 * a bracket scale and its application, a translated token — so they are
 * normalised here rather than in three near-identical row components.
 */
export interface RuleLayerView {
  claim: AccountRuleClaim | null;
  layer: AccountRuleLayer;
  measure: ReactNode;
  source: ProductRuleSource | null;
  validFrom: string;
  validTo: string | null;
  value: ReactNode;
  verification: RuleVerification | null;
}

export function ceilingLayers(
  rule: AccountCeilingRule,
  accountAsset: string,
  language: string,
): RuleLayerView[] {
  return present<AccountCeiling>(rule).map(([layer, ceiling]) => ({
    ...provenance(layer, ceiling),
    value: formatAmount(ceiling.amount.value, ceiling.amount.assetCode, language),
    measure: (
      <CeilingMeasure
        accountAsset={accountAsset}
        ceiling={ceiling}
        check={layer === rule.effectiveLayer ? (rule.check ?? null) : null}
        rule={rule}
      />
    ),
  }));
}

export function rateLayers(rule: AccountRateRule, accountAsset: string): RuleLayerView[] {
  return present<AccountRate>(rule).map(([layer, rate]) => ({
    ...provenance(layer, rate),
    value: <RateBrackets assetCode={accountAsset} rate={rate} />,
    measure: (
      <RateMeasure
        applied={rule.applied}
        assetCode={accountAsset}
        rate={rate}
        showApplied={layer === rule.effectiveLayer}
      />
    ),
  }));
}

export function termLayers(rule: AccountTermRule): RuleLayerView[] {
  return present<AccountTerm>(rule).map(([layer, term]) => ({
    ...provenance(layer, term),
    value: <TermWording token={term.token} />,
    measure: <TermMeasure />,
  }));
}

interface LayeredRule<T> {
  catalog: T | null;
  inherited: T | null;
  override: T | null;
}

/**
 * The authorities that actually answered, in reading order: what is published,
 * what the account follows, then what it claims locally. An absent layer is
 * left out rather than rendered as an empty row, which would read as "this
 * authority says nothing" when it says nothing because it is not in the chain.
 */
function present<T>(rule: LayeredRule<T>): Array<[AccountRuleLayer, T]> {
  const layers: Array<[AccountRuleLayer, T | null]> = [
    ['CATALOG', rule.catalog],
    ['INHERITED', rule.inherited],
    ['OVERRIDE', rule.override],
  ];

  return layers.filter((entry): entry is [AccountRuleLayer, T] => entry[1] !== null);
}

interface ProvenanceCarrier {
  claim: AccountRuleClaim | null;
  source: ProductRuleSource | null;
  validFrom: string;
  validTo: string | null;
  verification: RuleVerification | null;
}

function provenance(
  layer: AccountRuleLayer,
  carrier: ProvenanceCarrier,
): Omit<RuleLayerView, 'measure' | 'value'> {
  return {
    claim: carrier.claim,
    layer,
    source: carrier.source,
    validFrom: carrier.validFrom,
    validTo: carrier.validTo,
    verification: carrier.verification,
  };
}
