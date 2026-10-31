import type { AccountValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ceilingFillPercent } from '@/lib/depositCeiling';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './AccountValuationCell.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface AccountValuationCellProps {
  ceiling?: { assetCode: string; value: string } | null;
  size?: 'md' | 'lg';
  startingBalanceHint?: boolean;
  valuation: AccountValuation | null;
}

/**
 * Resolved balance and its snapshot or movement provenance.
 *
 * Source and display stay distinct: the backend already rounded. A missing
 * figure is a reason, never `0`. Quality is named in words so colour is not
 * the only signal.
 */
export function AccountValuationCell({
  ceiling = null,
  size = 'md',
  valuation,
  startingBalanceHint = false,
}: AccountValuationCellProps) {
  const { i18n, t } = useTranslation();
  const cellClassName = size === 'lg' ? `${styles.cell} ${styles.large}` : styles.cell;
  const showProvenance = size !== 'lg';

  if (valuation === null) {
    return (
      <div className={cellClassName}>
        {ceiling !== null ? (
          <div className={styles.ceiling}>
            <MoneyValue value={null} skeletonClassName={styles.skeletonFigure} />
            <span className={styles.track} aria-hidden="true">
              <span className={styles.fill} style={{ visibility: 'hidden' }} />
            </span>
          </div>
        ) : size === 'lg' ? (
          <span>
            <MoneyValue value={null} skeletonClassName={styles.skeletonFigure} />
          </span>
        ) : (
          <MoneyValue value={null} skeletonClassName={styles.skeletonFigure} />
        )}
        {showProvenance ? (
          <small>
            <SkeletonBlock className={styles.skeletonProvenance} />
          </small>
        ) : null}
      </div>
    );
  }

  if (valuation.quality === 'MISSING') {
    return (
      <div className={cellClassName}>
        <EmptyValue
          label={t('accounts.balances.missing')}
          reason={t(
            valuation.reason === 'MIXED_ASSETS'
              ? 'accounts.balances.mixedAssets'
              : startingBalanceHint
                ? 'accounts.balances.startingBalanceHint'
                : 'accounts.balances.qualities.MISSING',
          )}
        />
      </div>
    );
  }

  const derivation = valuation.basis === 'SNAPSHOT_PLUS_MOVEMENTS' ? valuation.derivation : null;
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
      {valuation.belowDisplayStep ? (
        <span className={styles.unknown}>{figure}</span>
      ) : !valuation.display ? (
        <EmptyValue label={t('accounts.balances.missing')} />
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
      {showProvenance ? (
        <small>
          {t(`accounts.balances.qualities.${valuation.quality}`)}
          {valuation.asOf && !derivation
            ? ` · ${t('accounts.balances.asOf', {
                date: formatCalendarDay(valuation.asOf, i18n.language),
              })}`
            : null}
          {derivation
            ? ` · ${t('accounts.balances.derivation', {
                date: formatCalendarDay(derivation.anchorAsOf, i18n.language),
                count: derivation.movementCount,
              })}`
            : valuation.source
              ? ` · ${t(`accounts.balances.sources.${valuation.source}`)}`
              : null}
          {derivation && derivation.pendingCount > 0
            ? ` · ${t('accounts.balances.pending', { count: derivation.pendingCount })}`
            : null}
          {valuation.quality === 'STALE' && valuation.ageDays !== null
            ? ` · ${t('accounts.balances.age', { count: valuation.ageDays })}`
            : null}
        </small>
      ) : null}
    </div>
  );
}
