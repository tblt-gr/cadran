import type { CategorizationRuleConditions } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { formatDecimal } from '@/lib/decimal';

const lower = (text: string) => text.charAt(0).toLowerCase() + text.slice(1);

/** The conditions of a rule as short clauses, in the order the form asks for them. */
export function conditionClauses(
  conditions: CategorizationRuleConditions,
  t: TFunction,
  language: string,
): string[] {
  const clauses: string[] = [];
  const text = conditions.text;
  if (text && text.predicates.length > 0) {
    const joiner = t(`categorizationRules.sentence.combinator.${text.combinator}`);
    const parts = text.predicates.map((predicate) => {
      const clause = t('categorizationRules.sentence.text', {
        source: lower(t(`categorizationRules.conditions.sources.${predicate.source}`)),
        operator: lower(t(`categorizationRules.operators.${predicate.operator}`)),
        value: predicate.value,
      });
      return predicate.negated ? t('categorizationRules.sentence.not', { clause }) : clause;
    });
    clauses.push(parts.join(joiner));
  }
  const amount = conditions.amount;
  if (amount && (amount.min !== null || amount.max !== null)) {
    const bounds = [
      amount.min === null
        ? null
        : t('categorizationRules.conditions.amountMin', {
            value: formatDecimal(amount.min, language),
            asset: amount.assetCode,
          }),
      amount.max === null
        ? null
        : t('categorizationRules.conditions.amountMax', {
            value: formatDecimal(amount.max, language),
            asset: amount.assetCode,
          }),
    ].filter((bound): bound is string => bound !== null);
    clauses.push(t('categorizationRules.sentence.amount', { bounds: bounds.join(' ') }));
  }
  if (conditions.direction) {
    clauses.push(t(`categorizationRules.sentence.direction.${conditions.direction}`));
  }
  if (conditions.mcc) {
    clauses.push(t('categorizationRules.conditions.mccValue', { value: conditions.mcc }));
  }
  return clauses;
}
