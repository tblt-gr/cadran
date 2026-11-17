import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import { monthLabel } from '@/features/reports/annual/annualRowLabels';
import { AnnualMonthCard } from '@/features/reports/annual/AnnualMonthCard';
import cardStyles from '@/features/reports/annual/AnnualMonthCards.module.css';
import type { DetailedReportViewProps } from './detailedReportProps';
import { DetailedAggregateCard } from './DetailedAggregateCard';
import { DetailedYearCard } from './DetailedYearCard';
import { MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS } from './multiYearDetailedAggregate';

interface DetailedCardsProps extends DetailedReportViewProps {
  /** The months of every year, the first year without its empty leading months. */
  rows: {
    annualReport: DetailedReportViewProps['annualReports'][number];
    row: DetailedReportViewProps['annualReports'][number]['rows'][number];
  }[];
}

/** The chronology as cards for narrow screens: a card per year and per open month, then the summary. */
export function DetailedCards({
  annualReports,
  columns,
  marksOf,
  onExplain,
  onToggleYear,
  opened,
  report,
  rows,
}: DetailedCardsProps) {
  const { t, i18n } = useTranslation();

  return (
    <ul
      aria-label={t('reports.multiYear.detailedCardsLabel', { from: report.from, to: report.to })}
      className={cardStyles.list}
    >
      {annualReports.map((annualReport, groupIndex) => {
        const year = report.years[groupIndex];
        const label = year?.year ?? annualReport.year;
        const open = opened.has(label);
        const monthRows = rows.filter((item) => item.annualReport === annualReport);
        return (
          <Fragment key={label}>
            <DetailedYearCard
              columns={columns}
              label={label}
              onToggle={onToggleYear}
              open={open}
              year={year}
            />
            {open
              ? monthRows.map(({ row }) => (
                  <AnnualMonthCard
                    columns={columns}
                    key={`${annualReport.year}-${row.month}`}
                    label={`${monthLabel(row.month, i18n.language)} ${annualReport.year}`}
                    marksOf={(columnId) => marksOf('months', row.month, columnId)}
                    row={row}
                  />
                ))
              : null}
          </Fragment>
        );
      })}
      <li>
        <h3 className={cardStyles.listHeading}>{t('reports.multiYear.detailedSummaryCard')}</h3>
      </li>
      {MULTI_YEAR_DETAILED_AGGREGATE_ROW_IDS.map((rowId) => (
        <DetailedAggregateCard
          columns={columns}
          key={rowId}
          onExplain={onExplain}
          report={report}
          rowId={rowId}
        />
      ))}
    </ul>
  );
}
