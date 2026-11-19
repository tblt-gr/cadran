import { useTranslation } from 'react-i18next';
import { LifeInsuranceSummaryPanel } from '@/features/portfolio/life-insurance/summary-panel/LifeInsuranceSummaryPanel';
import { TaxTrackingCard } from './TaxTrackingCard';
export function TaxTrackingPanel({ accountId, asOf }: { accountId: string; asOf: string }) {
  const { t } = useTranslation();
  return (
    <LifeInsuranceSummaryPanel
      accountId={accountId}
      asOf={asOf}
      title={t('portfolio.lifeInsurance.tax.unavailable')}
    >
      {(summary) => <TaxTrackingCard summary={summary} />}
    </LifeInsuranceSummaryPanel>
  );
}
