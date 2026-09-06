import type { ProductRule } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { formatAmount, formatDecimal } from '@/lib/decimal';

/**
 * Formats a catalogue rule the same way on the figure strip and in the
 * provenance table: the server string, never a computed or rounded substitute.
 */
export function formatProductRuleValue(rule: ProductRule, locale: string, t: TFunction): string {
  if (rule.amount !== null) {
    return formatAmount(rule.amount.value, rule.amount.assetCode, locale);
  }

  if (rule.percentage !== null) {
    return t('catalog.rules.percentValue', { value: formatDecimal(rule.percentage, locale) });
  }

  if (rule.text !== null) {
    const wording = ruleTextKey(rule.text);

    return wording === null ? rule.text : t(wording);
  }

  return t('catalog.rules.unknownValue');
}
