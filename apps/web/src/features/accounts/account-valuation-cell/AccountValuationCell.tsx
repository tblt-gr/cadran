import type { AccountValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { AMOUNT_MASK, areAmountsHidden } from '@/lib/amountPrivacy';
import { ceilingFillPercent } from '@/lib/depositCeiling';
import { formatAmount, formatCalendarDay, signedPresentation } from '@/lib/decimal';
import styles from './AccountValuationCell.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface AccountValuationCellProps {
  ceiling?: { assetCode: string; value: string } | null;
  size?: 'md' | 'lg';
  /** Hidden in the accounts list: freshness is stated on the account page. */
  provenance?: boolean;
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
  provenance = true,
  valuation,
  startingBalanceHint = false,
}: AccountValuationCellProps) {
  const { i18n, t } = useTranslation();
  const cellClassName = size === 'lg' ? `${styles.cell} ${styles.large}` : styles.cell;
  const showProvenance = size !== 'lg' && provenance;

  if (valuation === null) {
    return (
      <div className={cellClassName}>
        {ceiling !== null ? (
          <div className={styles.ceiling}>
            <span className={styles.figures}>
              <MoneyValue value={null} skeletonClassName={styles.skeletonFigure} />{' '}
              <span data-ceiling-part>
                <MoneyValue value={null} skeletonClassName={styles.skeletonFigure} />
              </span>
            </span>
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
  const hidden = areAmountsHidden();
  const sign = valuation.display ? signedPresentation('', valuation.display.value).sign : 0;
  const figure = hidden
    ? AMOUNT_MASK
    : valuation.belowDisplayStep
      ? t('accounts.balances.belowStep')
      : valuation.display
        ? formatAmount(valuation.display.value, valuation.display.assetCode, i18n.language)
        : t('accounts.balances.missing');
  const signedFigure =
    size === 'lg' && valuation.display && !valuation.belowDisplayStep
      ? signedPresentation(figure, valuation.display.value).text
      : figure;

  const ceilingLabel =
    ceiling === null ? null : formatAmount(ceiling.value, ceiling.assetCode, i18n.language);
  const ratio =
    hidden || ceiling === null || valuation.display === null
      ? null
      : ceilingFillPercent(valuation.display.value, ceiling.value);

  return (
    <div className={cellClassName}>
      {valuation.belowDisplayStep && !hidden ? (
        <span className={styles.unknown}>{figure}</span>
      ) : !valuation.display ? (
        <EmptyValue label={t('accounts.balances.missing')} />
      ) : ceilingLabel === null ? (
        size === 'lg' ? (
          <span data-sign={sign === 0 ? undefined : sign < 0 ? 'outflow' : 'inflow'}>
            <MoneyValue value={signedFigure} />
          </span>
        ) : (
          <MoneyValue value={signedFigure} />
        )
      ) : (
        <div className={styles.ceiling}>
          {/* The ceiling part carries data-ceiling-part so the mobile accounts list can drop it. */}
          <span className={styles.figures}>
            <MoneyValue value={figure} />{' '}
            <span data-ceiling-part>
              <MoneyValue
                value={
                  ceilingLabel === AMOUNT_MASK
                    ? AMOUNT_MASK
                    : t('accounts.balances.ceilingPart', { ceiling: ceilingLabel })
                }
              />
            </span>
          </span>
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
