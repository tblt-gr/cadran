import type { Product, ProductRule, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatProductRuleValue } from '@/features/catalog/formatProductRuleValue';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ProductFigures.module.css';

const FIGURE_KINDS = [
  'ANNUAL_RATE',
  'MIN_RATE',
  'DEPOSIT_CEILING',
  'BALANCE_CEILING',
  'CONTRIBUTION_CEILING',
  'COMBINED_CONTRIBUTION_CEILING',
] as const satisfies readonly ProductRuleKind[];

interface ProductFiguresProps {
  product: Product;
}

interface FigureItem {
  kind: (typeof FIGURE_KINDS)[number];
  rule: ProductRule | null;
}

/**
 * The rates and ceilings the catalogue can vouch for on the business date.
 *
 * Only a label and a value: the period, the verification and the source are in the details. Text
 * rules stay in the provenance table. An unavailable kind is stated as unavailable, never as zero.
 */
export function ProductFigures({ product }: ProductFiguresProps) {
  const { i18n, t } = useTranslation();
  const figures = collectFigures(product);

  if (figures.length === 0) {
    return null;
  }

  const titleId = `product-${product.code}-figures`;

  return (
    <section aria-labelledby={titleId} className={styles.section}>
      <h3 className="sr-only" id={titleId}>
        {t('catalog.figures.title', { date: formatCalendarDay(product.asOf, i18n.language) })}
      </h3>
      <dl className={styles.figures}>
        {figures.map((figure) => (
          <div key={figure.kind}>
            <dt>{t(`catalog.rules.kinds.${figure.kind}`)}</dt>
            {figure.rule === null ? (
              <dd className={styles.unavailable}>{t('catalog.figures.unavailable')}</dd>
            ) : (
              <dd className={`money ${styles.value}`}>
                {formatProductRuleValue(figure.rule, i18n.language, t)}
              </dd>
            )}
          </div>
        ))}
      </dl>
    </section>
  );
}

function collectFigures(product: Product): FigureItem[] {
  const sourced = new Map(product.rules.map((rule) => [rule.kind, rule]));
  const kinds = new Set<ProductRuleKind>([
    ...product.rules.map((rule) => rule.kind),
    ...product.unavailableRuleKinds,
  ]);

  return FIGURE_KINDS.filter((kind) => kinds.has(kind)).map((kind) => ({
    kind,
    rule: sourced.get(kind) ?? null,
  }));
}
