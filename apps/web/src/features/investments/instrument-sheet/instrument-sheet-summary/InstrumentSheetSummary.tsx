import type { InstrumentHolding } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { holdingsDay, holdingsMoney } from '@/features/investments/holdingsFormat';
import { formatDecimal } from '@/lib/decimal';
import { sheetGainTone } from '@/features/investments/instrument-sheet/sheetGainTone';
import { sheetReason } from '@/features/investments/instrument-sheet/sheetReason';
import styles from './InstrumentSheetSummary.module.css';

export function InstrumentSheetSummary({ data }: { data: InstrumentHolding | null }) {
  const { t, i18n } = useTranslation();
  const holding = data?.holding;
  const money = (value: string | null | undefined, signed = false) => {
    if (value == null) return <EmptyValue label={t('investments.summary.notCalculable')} />;
    const text = holdingsMoney(value, data!.instrument.currencyCode, i18n.language);
    return signed && !value.startsWith('-') && /[1-9]/.test(value) ? `+${text}` : text;
  };
  const empty = data !== null && !holding && data.accounts.length === 0;
  // Without any holding the page says it once, in its content, instead of six empty figures.
  if (empty) return null;
  const figure = (value: string | null | undefined, signed = false) =>
    data ? money(value, signed) : null;
  const tone = (value: string | null | undefined) => {
    const sign = data ? sheetGainTone(value) : null;
    return sign ? styles[sign] : '';
  };
  const dates = holding?.accounts.map((account) => account.valuedOn).sort() ?? [];
  return (
    <section className={`card ${styles.summary}`} aria-label={t('investments.sheet.summaryLabel')}>
      <div className={styles.strip}>
        <KpiFigure
          label={t('investments.sheet.quantityHeld')}
          size="lead"
          value={
            data ? (
              holding ? (
                formatDecimal(holding.quantity, i18n.language)
              ) : (
                <EmptyValue label={t('investments.sheet.noHolding')} />
              )
            ) : null
          }
        />
        <KpiFigure
          label={t('investments.sheet.value')}
          size="lead"
          value={figure(holding?.value)}
        />
        <KpiFigure
          label={t('investments.sheet.unrealizedGain')}
          size="compact"
          tone={tone(holding?.unrealizedGain)}
          value={figure(holding?.unrealizedGain, true)}
        />
        <KpiFigure
          label={t('investments.sheet.realizedGain')}
          size="compact"
          tone={tone(data?.totals.realizedGain)}
          value={figure(data?.totals.realizedGain, true)}
        />
        <KpiFigure
          label={t('investments.sheet.income')}
          size="compact"
          value={figure(data?.totals.income, true)}
        />
        <KpiFigure
          label={t('investments.sheet.totalGain')}
          size="compact"
          tone={tone(data?.totals.totalGain)}
          value={figure(data?.totals.totalGain, true)}
        />
      </div>
      <p className={styles.note}>
        {data === null ? (
          <SkeletonBlock className={styles.dateBlock} />
        ) : dates.length ? (
          dates[0] === dates[dates.length - 1] ? (
            t('investments.sheet.valuedOn', { date: holdingsDay(dates[0]!, i18n.language) })
          ) : (
            t('investments.sheet.valuedBetween', {
              from: holdingsDay(dates[0]!, i18n.language),
              to: holdingsDay(dates[dates.length - 1]!, i18n.language),
            })
          )
        ) : null}
      </p>
      {data?.totals.reason ? (
        <p className={styles.note}>{sheetReason(data.totals.reason, t)}</p>
      ) : null}
    </section>
  );
}
