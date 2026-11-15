import type { AnnualReport, MultiYearReport } from '@cadran/api-client';

export const FLOW_SERIES_IDS = ['cashIncome', 'budgetExpenses', 'budgetSurplus'] as const;
export const NET_WORTH_ID = 'endNetWorth';

export interface MultiYearChartsProps {
  annualReports?: AnnualReport[];
  report: MultiYearReport;
}

/** The flows are read per month, or one bar per year. */
export interface MultiYearFlowsProps extends MultiYearChartsProps {
  byYear: boolean;
}

export function columnLookup(report: MultiYearReport, id: string) {
  return report.columns.find((column) => column.id === id) ?? null;
}

export function flowAssetCode(annualReports: AnnualReport[] | undefined, report: MultiYearReport) {
  return (
    annualReports?.find((annualReport) => annualReport.charts.flows.assetCode !== null)?.charts
      .flows.assetCode ??
    report.years.find((year) => year.flows.assetCode !== null)?.flows.assetCode ??
    null
  );
}
