import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAnnualValue } from './annualFormat';

interface AnnualCellValueProps {
  assetCode: string | null;
  /** Drill-down target; the cell renders a link when the value is present. */
  href?: string;
  kind: 'FLOW' | 'STOCK' | 'RATE';
  reason: string | null;
  value: string | null;
}

/** Formats one exact backend value; a missing value shows its reason as visible text. */
export function AnnualCellValue({ assetCode, href, kind, reason, value }: AnnualCellValueProps) {
  const { t, i18n } = useTranslation();
  if (value === null) {
    return (
      <span>
        {t('reports.annual.notCalculable')}
        {reason ? ` : ${t(`reports.annual.reasons.${reason}`, { defaultValue: reason })}` : ''}
      </span>
    );
  }
  const text = formatAnnualValue(value, kind, assetCode, i18n.language);

  return href ? (
    <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
      {text}
    </a>
  ) : (
    <span>{text}</span>
  );
}
