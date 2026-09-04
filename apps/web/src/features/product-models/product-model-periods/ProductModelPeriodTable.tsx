import type { ProductModelRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarDay, formatDecimal } from '@/lib/decimal';
import styles from './ProductModelPeriodTable.module.css';

interface ProductModelPeriodTableProps {
  name: string;
  rules: ProductModelRule[];
}

/**
 * Every dated period a model has ever recorded. It stays readable for an
 * archived model too: an account created from it must keep resolving against
 * the description it was created with. A null end is in force with no known
 * end — never shown as an expiry or a zero.
 */
export function ProductModelPeriodTable({ name, rules }: ProductModelPeriodTableProps) {
  const { i18n, t } = useTranslation();

  if (rules.length === 0) {
    return <p className={styles.empty}>{t('productModels.periods.none')}</p>;
  }

  return (
    <div className={styles.tableScroll}>
      <table>
        <caption className="sr-only">{t('productModels.periods.caption', { name })}</caption>
        <thead>
          <tr>
            <th scope="col">{t('productModels.periods.columns.rule')}</th>
            <th scope="col">{t('productModels.periods.columns.value')}</th>
            <th scope="col">{t('productModels.periods.columns.mode')}</th>
            <th scope="col">{t('productModels.periods.columns.period')}</th>
          </tr>
        </thead>
        <tbody>
          {rules.map((rule) => (
            <tr key={rule.id}>
              <th scope="row">{t(`catalog.rules.kinds.${rule.kind}`)}</th>
              <td>
                <PeriodValue rule={rule} />
              </td>
              <td>
                {rule.valueType === 'PERCENTAGE' &&
                rule.rateApplication !== null &&
                rule.brackets.length > 1 ? (
                  <span>{t(`accounts.rules.applications.${rule.rateApplication}`)}</span>
                ) : (
                  <span className={styles.notApplicable}>{t('productModels.periods.noMode')}</span>
                )}
              </td>
              <td>
                {rule.validTo === null
                  ? t('catalog.rules.openPeriod', {
                      from: formatCalendarDay(rule.validFrom, i18n.language),
                    })
                  : t('catalog.rules.closedPeriod', {
                      from: formatCalendarDay(rule.validFrom, i18n.language),
                      to: formatCalendarDay(rule.validTo, i18n.language),
                    })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PeriodValue({ rule }: { rule: ProductModelRule }) {
  const { i18n, t } = useTranslation();

  if (rule.valueType === 'AMOUNT' && rule.amount !== null) {
    return <>{formatAmount(rule.amount.value, rule.amount.assetCode, i18n.language)}</>;
  }

  if (rule.valueType === 'TEXT' && rule.text !== null) {
    return <>{rule.text}</>;
  }

  if (rule.valueType === 'PERCENTAGE') {
    const [only] = rule.brackets;
    if (rule.brackets.length === 1 && only !== undefined && only.upperBound === null) {
      return (
        <>
          {t('catalog.rules.percentValue', {
            value: formatDecimal(only.percentage, i18n.language),
          })}
        </>
      );
    }

    return (
      <ol className={styles.brackets}>
        {rule.brackets.map((bracket) => (
          <li key={bracket.lowerBound}>
            <span>
              {t('catalog.rules.percentValue', {
                value: formatDecimal(bracket.percentage, i18n.language),
              })}
            </span>
            <small>
              {bracket.upperBound === null
                ? t('accounts.rules.bracketFrom', {
                    from: formatDecimal(bracket.lowerBound, i18n.language),
                  })
                : t('accounts.rules.bracketRange', {
                    from: formatDecimal(bracket.lowerBound, i18n.language),
                    to: formatDecimal(bracket.upperBound, i18n.language),
                  })}
            </small>
          </li>
        ))}
      </ol>
    );
  }

  return <span className={styles.notApplicable}>{t('productModels.periods.noValue')}</span>;
}
