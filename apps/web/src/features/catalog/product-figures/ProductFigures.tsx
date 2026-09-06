import type { Product, ProductRule, ProductRuleKind } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
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

const verificationTone = {
  VERIFIED: 'positive',
  STALE: 'warning',
  UNVERIFIED: 'info',
} as const;

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
 * Text rules stay in the provenance table: a figure strip that mixed
 * eligibility wording with a deposit ceiling would bury the amount. An
 * unavailable kind is stated as unavailable, never as zero.
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
              <dd className={styles.unavailable}>
                {t('catalog.figures.unavailable', {
                  date: formatCalendarDay(product.asOf, i18n.language),
                })}
              </dd>
            ) : (
              <>
                <dd className={`money ${styles.value}`}>
                  {formatProductRuleValue(figure.rule, i18n.language, t)}
                </dd>
                <dd className={styles.period}>
                  {formatFigurePeriod(figure.rule, i18n.language, t)}
                </dd>
                {figure.rule.verification === null ? null : (
                  <dd>
                    <StatusBadge tone={verificationTone[figure.rule.verification]}>
                      {t(`catalog.verification.${figure.rule.verification}`)}
                    </StatusBadge>
                  </dd>
                )}
              </>
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

function formatFigurePeriod(rule: ProductRule, locale: string, t: TFunction): string {
  const from = formatCalendarDay(rule.validFrom, locale);

  return rule.validTo === null
    ? t('catalog.rules.openPeriod', { from })
    : t('catalog.rules.closedPeriod', {
        from,
        to: formatCalendarDay(rule.validTo, locale),
      });
}
