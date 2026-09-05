import type { Product, ProductRule, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { RuleProvenance } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { formatAmount, formatCalendarDay, formatDecimal } from '@/lib/decimal';
import styles from './ProductRuleList.module.css';

interface ProductRuleListProps {
  product: Product;
}

/**
 * The rules of one product on the business date, each with the period it covers
 * and the publication it was read from. Two things this table must never do:
 * show a figure without its source, and show an unavailable rule as a zero.
 */
export function ProductRuleList({ product }: ProductRuleListProps) {
  const { i18n, t } = useTranslation();

  if (product.rules.length === 0 && product.unavailableRuleKinds.length === 0) {
    return (
      <p className={styles.none}>
        {t('catalog.rules.none', { date: formatCalendarDay(product.asOf, i18n.language) })}
      </p>
    );
  }

  return (
    <div className={styles.tableScroll}>
      <table>
        <caption className="sr-only">
          {t('catalog.list.caption', { product: product.displayName })}
        </caption>
        <thead>
          <tr>
            <th scope="col">{t('catalog.list.rule')}</th>
            <th scope="col">{t('catalog.list.value')}</th>
            <th scope="col">{t('catalog.list.period')}</th>
            <th scope="col">{t('catalog.list.verification')}</th>
            <th scope="col">{t('catalog.list.source')}</th>
          </tr>
        </thead>
        <tbody>
          {product.rules.map((rule) => (
            <tr key={rule.kind}>
              <th scope="row">{t(`catalog.rules.kinds.${rule.kind}`)}</th>
              <td className={styles.value}>
                {formatRuleValue(rule, i18n.language, t as Translate)}
              </td>
              <RuleProvenance
                source={rule.source}
                validFrom={rule.validFrom}
                validTo={rule.validTo}
                verification={rule.verification}
              />
            </tr>
          ))}
          {product.unavailableRuleKinds.map((kind: ProductRuleKind) => (
            <tr key={kind}>
              <th scope="row">{t(`catalog.rules.kinds.${kind}`)}</th>
              {/* Not a zero and not an empty cell: the value is unknown for this
                  date, and the reason travels with the dash. */}
              <td className={styles.unavailable} colSpan={4}>
                {t('catalog.rules.unavailable', {
                  date: formatCalendarDay(product.asOf, i18n.language),
                })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

type Translate = (key: string, options?: Record<string, unknown>) => string;

function formatRuleValue(rule: ProductRule, locale: string, t: Translate): string {
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
