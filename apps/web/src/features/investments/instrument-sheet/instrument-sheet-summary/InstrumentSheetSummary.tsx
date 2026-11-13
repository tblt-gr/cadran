import type { InstrumentHolding } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { KpiFigure } from '@/features/portfolio/portfolio-account/portfolio-kpi-grid/KpiFigure';
import { holdingsDay, holdingsMoney } from '@/features/investments/holdingsFormat';
import { formatDecimal } from '@/lib/decimal';
import { sheetGainTone } from '@/features/investments/instrument-sheet/sheetGainTone';
import { sheetReason } from '@/features/investments/instrument-sheet/sheetReason';
import styles from './InstrumentSheetSummary.module.css';

export function InstrumentSheetSummary({ data }: { data: InstrumentHolding | null }) {
  const holding = data?.holding;
  const money = (value: string | null | undefined, signed = false) => {
    if (value == null) return <EmptyValue label="Non calculable" />;
    const text = holdingsMoney(value, data!.instrument.currencyCode);
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
    <section className={`card ${styles.summary}`} aria-label="Synthèse de l'instrument">
      <div className={styles.strip}>
        <KpiFigure
          label="Quantité détenue"
          size="lead"
          value={
            data ? (
              holding ? (
                formatDecimal(holding.quantity, 'fr')
              ) : (
                <EmptyValue label="Aucune détention" />
              )
            ) : null
          }
        />
        <KpiFigure label="Valeur" size="lead" value={figure(holding?.value)} />
        <KpiFigure
          label="Plus-value latente"
          size="compact"
          tone={tone(holding?.unrealizedGain)}
          value={figure(holding?.unrealizedGain, true)}
        />
        <KpiFigure
          label="Plus-value réalisée"
          size="compact"
          tone={tone(data?.totals.realizedGain)}
          value={figure(data?.totals.realizedGain, true)}
        />
        <KpiFigure
          label="Revenus perçus"
          size="compact"
          value={figure(data?.totals.income, true)}
        />
        <KpiFigure
          label="Gain total"
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
            `Valorisation au ${holdingsDay(dates[0]!)}`
          ) : (
            `Valorisations du ${holdingsDay(dates[0]!)} au ${holdingsDay(dates[dates.length - 1]!)}`
          )
        ) : null}
      </p>
      {data?.totals.reason ? (
        <p className={styles.note}>{sheetReason(data.totals.reason)}</p>
      ) : null}
    </section>
  );
}
