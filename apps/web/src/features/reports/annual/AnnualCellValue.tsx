import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { formatAnnualValue } from './annualFormat';

interface AnnualCellValueProps {
  assetCode: string | null;
  kind: 'FLOW' | 'STOCK' | 'RATE';
  reason: string | null;
  value: string | null;
}

/** Formats one exact backend value; a missing value shows a dash and keeps its reason as hidden text. */
export function AnnualCellValue({ assetCode, kind, reason, value }: AnnualCellValueProps) {
  const { t, i18n } = useTranslation();
  if (value === null) {
    return (
      <EmptyValue
        label={t('reports.annual.notCalculable')}
        reason={reason ? t(`reports.annual.reasons.${reason}`, { defaultValue: reason }) : null}
      />
    );
  }
  const text = formatAnnualValue(value, kind, assetCode, i18n.language);

  return <span>{text}</span>;
}
