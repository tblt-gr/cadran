import type {
  GoalMonthlyStatus,
  GoalMonthlyStatusSummary,
  GoalStatus,
  GoalStatusSummary,
  GoalYearlyStatus,
  GoalYearlyStatusSummary,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ProgressGauge } from '@/components/ui/progress-gauge/ProgressGauge';
import { compareDecimals, decimalRatio, negateDecimal } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { formatGoalFigure } from './goalFormat';
import styles from './GoalsPage.module.css';

type Status = GoalStatus | GoalStatusSummary;
type Yearly = GoalYearlyStatus | GoalYearlyStatusSummary;
type Monthly = GoalMonthlyStatus | GoalMonthlyStatusSummary;

interface Props {
  assetCode: string;
  status: Status;
}

/** The figures against the target, the gap in words, and the same figures as a gold bar. */
export function GoalPosition({ assetCode, status }: Props) {
  const { t, i18n } = useTranslation();
  const annual = status.periodicity === 'YEARLY' ? (status as Yearly) : null;
  const monthly = status.periodicity === 'MONTHLY' ? (status as Monthly) : null;
  const done = annual ? annual.achieved : (monthly?.actual ?? null);
  const target = status.target;
  const figure = (value: string) =>
    formatGoalFigure(value, status.valueType, assetCode, i18n.language);
  const noValue = (
    <EmptyValue label={t(`goals.reasons.${status.reason ?? 'MISSING_MONTH_VALUE'}`)} />
  );
  const money = (value: string | null) => (value === null ? noValue : figure(value));

  const ceiling = status.direction === 'CEILING';
  // A sign is spelled out as a word so the meaning never rests on a "-" or a colour alone.
  const gapAmount = (
    value: string,
    key: 'shortfall' | 'surplus' | 'ceilingMargin' | 'ceilingOver',
  ) => t(`goals.position.${key}` as const, { amount: figure(value) });
  let gap: React.ReactNode = noValue;
  if (annual) {
    const excess = annual.excess;
    if (excess !== null && compareDecimals(excess, '0') > 0) {
      gap = gapAmount(excess, ceiling ? 'ceilingOver' : 'surplus');
    } else if (annual.remaining !== null) {
      gap =
        compareDecimals(annual.remaining, '0') === 0 && !ceiling
          ? t('goals.position.reached')
          : gapAmount(annual.remaining, ceiling ? 'ceilingMargin' : 'shortfall');
    }
  } else if (monthly && monthly.gap !== null) {
    const order = compareDecimals(monthly.gap, '0');
    const magnitude = order < 0 ? negateDecimal(monthly.gap) : monthly.gap;
    gap =
      order === 0 && !ceiling
        ? t('goals.position.reached')
        : gapAmount(
            magnitude,
            ceiling
              ? order > 0
                ? 'ceilingOver'
                : 'ceilingMargin'
              : order < 0
                ? 'shortfall'
                : 'surplus',
          );
  }

  const targetPositive = target !== null && compareDecimals(target, '0') > 0;
  const ratio = annual
    ? annual.progressRatioDisplay
    : targetPositive && done !== null
      ? decimalRatio(done, target, 6)
      : null;
  const marker =
    annual && annual.expected !== null && targetPositive
      ? decimalRatio(annual.expected, target, 6)
      : null;

  return (
    <>
      <div className={styles.position}>
        <p className={styles.amounts}>
          <span className="sr-only">{t('goals.position.label')}</span>
          <strong className="money">{money(done)}</strong>
          <span aria-hidden="true" className={styles.separator}>
            /
          </span>
          <span className={`money ${styles.required}`}>{money(target)}</span>
        </p>
        <p className={styles.gap}>{gap}</p>
      </div>
      {ratio === null ? null : (
        <ProgressGauge
          label={t('goals.progressLabel', {
            value: formatRatioPercentage(ratio, i18n.language),
          })}
          markerRatio={marker}
          ratio={ratio}
        />
      )}
      {annual ? (
        <p className={styles.caption}>
          {t('goals.position.expected', { amount: money(annual.expected) })}
        </p>
      ) : monthly && monthly.ratio !== null && status.valueType !== 'RATE' ? (
        <p className={styles.caption}>
          {t('goals.position.ratio', {
            ratio: formatRatioPercentage(monthly.ratio, i18n.language),
          })}
        </p>
      ) : null}
    </>
  );
}
