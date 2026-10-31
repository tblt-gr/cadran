import type { AccountRate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatPercentDecimal } from '@/lib/decimal';
import styles from './RateBrackets.module.css';

interface RateBracketsProps {
  assetCode: string;
  rate: AccountRate;
  /** `figure` matches a headline amount. The default stays the compact rules face. */
  variant?: 'compact' | 'figure';
}

/**
 * The scale a resolved rate is made of.
 *
 * A single published rate arrives as one slice running from zero without limit,
 * so it reads as a plain percentage. A tiered product arrives later as more
 * slices and needs nothing new here, which is the reason a rate is never
 * transported as a bare percentage.
 */
export function RateBrackets({ assetCode, rate, variant = 'compact' }: RateBracketsProps) {
  const { i18n, t } = useTranslation();
  const percentageClass = variant === 'figure' ? styles.figure : styles.percentage;

  const [only] = rate.brackets;
  if (rate.brackets.length === 1 && only !== undefined && only.upperBound === null) {
    return (
      <span className={variant === 'figure' ? styles.figure : styles.single}>
        {t('catalog.rules.percentValue', {
          value: formatPercentDecimal(only.percentage, i18n.language),
        })}
      </span>
    );
  }

  return (
    <ol className={styles.brackets}>
      {rate.brackets.map((bracket) => (
        <li key={bracket.lowerBound}>
          <span className={percentageClass}>
            {t('catalog.rules.percentValue', {
              value: formatPercentDecimal(bracket.percentage, i18n.language),
            })}
          </span>
          <small>
            {bracket.upperBound === null
              ? t('accounts.rules.bracketFrom', {
                  from: formatAmount(bracket.lowerBound, assetCode, i18n.language),
                })
              : t('accounts.rules.bracketRange', {
                  from: formatAmount(bracket.lowerBound, assetCode, i18n.language),
                  to: formatAmount(bracket.upperBound, assetCode, i18n.language),
                })}
          </small>
        </li>
      ))}
    </ol>
  );
}
