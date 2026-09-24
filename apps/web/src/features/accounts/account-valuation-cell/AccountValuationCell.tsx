import type { AccountValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ceilingFillPercent } from '@/lib/depositCeiling';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './AccountValuationCell.module.css';

interface AccountValuationCellProps {
  ceiling?: { assetCode: string; value: string } | null;
  size?: 'md' | 'lg';
  valuation: AccountValuation;
}

/**
 * Latest valid observed balance on the requested date.
 *
 * Source and display stay distinct: the backend already rounded. A missing
 * figure is a reason, never `0`. Quality is named in words so colour is not
 * the only signal.
 */
export function AccountValuationCell({
  ceiling = null,
  size = 'md',
  valuation,
}: AccountValuationCellProps) {
  const { i18n, t } = useTranslation();
  const cellClassName = size === 'lg' ? `${styles.cell} ${styles.large}` : styles.cell;

  if (valuation.quality === 'MISSING') {
    return (
      <div className={cellClassName}>
        <span className={styles.unknown}>{t('accounts.balances.missing')}</span>
        <small>{t('accounts.balances.qualities.MISSING')}</small>
      </div>
    );
  }

  const negative = valuation.display !== null && valuation.display.value.startsWith('-');
  const figure = valuation.belowDisplayStep
    ? t('accounts.balances.belowStep')
    : valuation.display
      ? formatAmount(valuation.display.value, valuation.display.assetCode, i18n.language)
      : t('accounts.balances.missing');
  // A colour never carries the sign alone: the balance itself already reads
  // "-" or a forced "+", so a reader who cannot tell red from green still
  // gets the same fact.
  const signedFigure =
    size === 'lg' && valuation.display && !valuation.belowDisplayStep
      ? negative || figure.startsWith('+')
        ? figure
        : `+${figure}`
      : figure;

  const ceilingLabel =
    ceiling === null ? null : formatAmount(ceiling.value, ceiling.assetCode, i18n.language);
  const ratio =
    ceiling === null || valuation.display === null
      ? null
      : ceilingFillPercent(valuation.display.value, ceiling.value);

  return (
    <div className={cellClassName}>
      {valuation.belowDisplayStep || !valuation.display ? (
        <span className={styles.unknown}>{figure}</span>
      ) : ceilingLabel === null ? (
        size === 'lg' ? (
          <span data-sign={negative ? 'outflow' : 'inflow'}>
            <MoneyValue value={signedFigure} />
          </span>
        ) : (
          <MoneyValue value={signedFigure} />
        )
      ) : (
        <div className={styles.ceiling}>
          <MoneyValue
            value={t('accounts.balances.againstCeiling', {
              ceiling: ceilingLabel,
              current: figure,
            })}
          />
          {ratio === null ? null : (
            <span className={styles.track} aria-hidden="true">
              <span className={styles.fill} style={{ width: `${ratio}%` }} />
            </span>
          )}
        </div>
      )}
      <small>
        {t(`accounts.balances.qualities.${valuation.quality}`)}
        {valuation.asOf
          ? ` · ${t('accounts.balances.asOf', {
              date: formatCalendarDay(valuation.asOf, i18n.language),
            })}`
          : null}
        {valuation.source ? ` · ${t(`accounts.balances.sources.${valuation.source}`)}` : null}
        {valuation.quality === 'STALE' && valuation.ageDays !== null
          ? ` · ${t('accounts.balances.age', { count: valuation.ageDays })}`
          : null}
      </small>
    </div>
  );
}
