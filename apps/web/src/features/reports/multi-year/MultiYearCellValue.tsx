import type { MultiYearCell } from '@cadran/api-client';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAnnualValue } from '@/features/reports/annual/annualFormat';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import { formatSignedChange, formatSignedRatioPoints } from './multiYearFormat';
import styles from '@/features/reports/annual/PerformanceValue.module.css';

interface MultiYearCellValueProps {
  assetCode: string | null;
  cell: MultiYearCell;
  kind: 'FLOW' | 'STOCK' | 'RATE';
}

/**
 * A year's value for one column, with its year-over-year change below it. The
 * change carries an explicit `+`/`-` text sign, never colour alone. A RATE
 * column's `change` is shown in ratio points (0.05 = "+5,00 points"), never a
 * percentage, so it is never mistaken for `changeRatio`'s relative growth.
 */
export function MultiYearCellValue({ assetCode, cell, kind }: MultiYearCellValueProps) {
  const { t, i18n } = useTranslation();

  function reasonText(reason: string): string {
    return t(`reports.multiYear.reasons.${reason}`, { defaultValue: reason });
  }

  if (cell.value === null) {
    return (
      <EmptyValue
        label={t('reports.annual.notCalculable')}
        reason={cell.reason ? reasonText(cell.reason) : null}
      />
    );
  }

  const text = formatAnnualValue(cell.value, kind, assetCode, i18n.language);
  const asOf = cell.asOf ? ` (${monthLabel(cell.asOf, i18n.language)})` : '';

  let change: ReactNode;
  if (cell.change === null) {
    change = (
      <EmptyValue
        label={t('reports.multiYear.change')}
        reason={cell.changeReason ? reasonText(cell.changeReason) : null}
      />
    );
  } else if (kind === 'RATE') {
    change = formatSignedRatioPoints(cell.change, i18n.language);
  } else {
    const changeText = formatSignedChange(cell.change, kind, assetCode, i18n.language);
    let ratio: ReactNode = null;
    if (cell.changeRatio !== null) {
      ratio = ` (${formatSignedChange(cell.changeRatio, 'RATE', null, i18n.language)})`;
    } else if (cell.changeReason) {
      // The change itself is exact, but its ratio failed (ZERO_BASE / NEGATIVE_BASE):
      // the reason still needs to reach the reader instead of the ratio silently vanishing.
      ratio = (
        <>
          {' ('}
          <EmptyValue
            label={t('reports.multiYear.changeRatio')}
            reason={reasonText(cell.changeReason)}
          />
          {')'}
        </>
      );
    }
    change = (
      <>
        {changeText}
        {ratio}
      </>
    );
  }

  return (
    <>
      <span
        className={
          cell.value.startsWith('-')
            ? styles.negative
            : cell.value === '0'
              ? styles.zero
              : styles.positive
        }
      >
        {text}
        {asOf}
      </span>
      <br />
      <small>{change}</small>
    </>
  );
}
