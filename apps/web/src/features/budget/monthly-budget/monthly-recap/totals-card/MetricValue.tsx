import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { reasonKeys, type RecapMetric } from './recapMetric';

export function MetricValue({ metric, rate }: { metric: RecapMetric; rate?: boolean }) {
  const { i18n, t } = useTranslation();
  if (metric.value === null || (metric.assetCode === null && !rate)) {
    return (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={metric.reason ? t(reasonKeys[metric.reason]) : null}
      />
    );
  }
  if (rate) return <MoneyValue value={formatRatioPercentage(metric.value, i18n.language)} />;
  return <MoneyValue value={formatAmount(metric.value, metric.assetCode!, i18n.language)} />;
}
