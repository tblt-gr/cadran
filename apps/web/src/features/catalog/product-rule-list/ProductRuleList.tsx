import type { Product, ProductRule, ProductRuleKind } from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatAmount, formatCalendarDay, formatDecimal } from '@/lib/decimal';
import styles from './ProductRuleList.module.css';

interface ProductRuleListProps {
  product: Product;
}

/**
 * Rule text values are uppercase tokens, so the wording lives in the
 * translation catalogue rather than in the database. A token this locale has
 * not learned yet is shown as itself instead of as a missing key.
 */
const ruleTextKeys = {
  NO_REGULATORY_CONTRIBUTION_CEILING: 'catalog.ruleTexts.NO_REGULATORY_CONTRIBUTION_CEILING',
} as const satisfies Record<string, ParseKeys>;

const verificationTone = {
  VERIFIED: 'positive',
  STALE: 'warning',
  UNVERIFIED: 'info',
} as const;

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
              <td className={styles.value}>{formatRuleValue(rule, i18n.language, t)}</td>
              <td>{formatPeriod(rule, i18n.language, t)}</td>
              <td>
                <StatusBadge tone={verificationTone[rule.verification]}>
                  {t(`catalog.verification.${rule.verification}`)}
                </StatusBadge>
              </td>
              <td>
                <a href={rule.source.url} rel="noreferrer noopener" target="_blank">
                  {rule.source.title}
                </a>
                <small>
                  {t('catalog.source.trace', {
                    publisher: rule.source.publisher,
                    retrieved: formatCalendarDay(rule.source.retrievedOn, i18n.language),
                  })}
                </small>
              </td>
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

type Translate = ReturnType<typeof useTranslation>['t'];

function formatRuleValue(rule: ProductRule, locale: string, t: Translate): string {
  if (rule.amount !== null) {
    return formatAmount(rule.amount.value, rule.amount.assetCode, locale);
  }

  if (rule.percentage !== null) {
    return t('catalog.rules.percentValue', { value: formatDecimal(rule.percentage, locale) });
  }

  if (rule.text !== null) {
    const key = ruleTextKeys[rule.text as keyof typeof ruleTextKeys];

    return key === undefined ? rule.text : t(key);
  }

  return t('catalog.rules.unknownValue');
}

function formatPeriod(rule: ProductRule, locale: string, t: Translate): string {
  const from = formatCalendarDay(rule.validFrom, locale);

  return rule.validTo === null
    ? t('catalog.rules.openPeriod', { from })
    : t('catalog.rules.closedPeriod', { from, to: formatCalendarDay(rule.validTo, locale) });
}
