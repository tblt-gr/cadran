import type { PortfolioPerformance } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';
import { methodFacts, type Fact } from './methodFacts';
import styles from './PerformanceExplanationModal.module.css';

type Freshness = PortfolioPerformance['gain']['freshness']['start'];

function anchorLine(
  title: string,
  anchor: PortfolioPerformance['start'],
  freshness: Freshness,
  assetCode: string,
  t: TFunction,
  locale: string,
) {
  if (!anchor) return t('portfolio.performance.anchor.none', { title });
  const age = freshness
    ? t('portfolio.performance.anchor.age', {
        count: freshness.ageDays,
        quality: freshness.priceQuality
          ? t(`portfolio.performance.quality.${freshness.priceQuality}`)
          : t('portfolio.performance.anchor.noPrice'),
      })
    : '';
  return t('portfolio.performance.anchor.line', {
    title,
    date: formatCalendarNumericDay(anchor.date),
    amount: formatAmount(anchor.totalValue, assetCode, locale),
    age,
  });
}

function Facts({ facts }: { facts: Fact[] }) {
  return (
    <dl className={styles.facts}>
      {facts.map(([label, value]) => (
        <div className={styles.fact} key={label}>
          <dt>{label}</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function Remaining({ count, shown }: { count: number; shown: number }) {
  const { t } = useTranslation();
  return count > shown ? (
    <p className={styles.muted}>{t('portfolio.performance.remaining', { count: count - shown })}</p>
  ) : null;
}

function Table({ caption, head, rows }: { caption: string; head: string[]; rows: string[][] }) {
  return (
    <div className={styles.scroll} role="region" aria-label={caption} tabIndex={0}>
      <table className={styles.table}>
        <caption>{caption}</caption>
        <thead>
          <tr>
            {head.map((label) => (
              <th key={label} scope="col">
                {label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={index}>
              {row.map((cell, cellIndex) => (
                <td key={cellIndex}>{cell}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function MethodTable({
  performance,
  figure,
}: {
  performance: PortfolioPerformance;
  figure: FigureKey;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language;
  const money = (value: string | null) =>
    value === null ? '-' : formatAmount(value, performance.assetCode, locale);
  if (figure === 'modifiedDietz') {
    const { weights, weightCount } = performance.modifiedDietz;
    if (weights.length === 0) return null;
    return (
      <>
        <Table
          caption={t('portfolio.performance.tables.weighted.caption')}
          head={[
            t('portfolio.performance.tables.weighted.date'),
            t('portfolio.performance.tables.weighted.amount'),
            t('portfolio.performance.tables.weighted.remainingDays'),
            t('portfolio.performance.tables.weighted.weight'),
          ]}
          rows={weights.map((flow) => [
            formatCalendarNumericDay(flow.date),
            money(flow.amount),
            flow.remainingDays,
            formatRatioPercentage(flow.weight, locale),
          ])}
        />
        <Remaining count={weightCount} shown={weights.length} />
      </>
    );
  }
  if (figure === 'timeWeighted') {
    const { subperiods, subperiodCount } = performance.timeWeighted;
    if (subperiods.length === 0) return null;
    return (
      <>
        <Table
          caption={t('portfolio.performance.tables.subperiods.caption')}
          head={[
            t('portfolio.performance.tables.subperiods.from'),
            t('portfolio.performance.tables.subperiods.to'),
            t('portfolio.performance.tables.subperiods.startValue'),
            t('portfolio.performance.tables.subperiods.endValue'),
            t('portfolio.performance.tables.subperiods.flow'),
            t('portfolio.performance.tables.subperiods.return'),
          ]}
          rows={subperiods.map((sub) => [
            formatCalendarNumericDay(sub.from),
            formatCalendarNumericDay(sub.to),
            money(sub.startValue),
            money(sub.endValue),
            money(sub.flow),
            sub.value === null ? '-' : formatRatioPercentage(sub.value, locale),
          ])}
        />
        <Remaining count={subperiodCount} shown={subperiods.length} />
      </>
    );
  }
  if (figure === 'moneyWeighted') {
    const { cashFlows, cashFlowCount } = performance.moneyWeighted;
    if (cashFlows.length === 0) return null;
    return (
      <>
        <Table
          caption={t('portfolio.performance.tables.investorFlows.caption')}
          head={[
            t('portfolio.performance.tables.investorFlows.date'),
            t('portfolio.performance.tables.investorFlows.amount'),
          ]}
          rows={cashFlows.map((flow) => [formatCalendarNumericDay(flow.date), money(flow.amount)])}
        />
        <Remaining count={cashFlowCount} shown={cashFlows.length} />
      </>
    );
  }
  return null;
}

/** The anchors, the named inputs of the method and the list the method works from. */
export function ExplanationEntries({
  performance,
  figure,
}: {
  performance: PortfolioPerformance;
  figure: FigureKey;
}) {
  const { t, i18n } = useTranslation();
  const { freshness } = performance[figure];
  const anchor = (key: 'start' | 'end') =>
    anchorLine(
      t(`portfolio.performance.anchor.${key}`),
      performance[key],
      freshness[key],
      performance.assetCode,
      t,
      i18n.language,
    );
  return (
    <div className={styles.content}>
      <ul>
        <li>{anchor('start')}</li>
        <li>{anchor('end')}</li>
      </ul>
      <Facts facts={methodFacts(performance, figure, t, i18n.language)} />
      <MethodTable figure={figure} performance={performance} />
    </div>
  );
}
