import type { PortfolioOperationType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { OperationField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationField';
import {
  allowedOperationTypes,
  operationTypeLabel,
} from '@/features/portfolio/portfolio-account/operations/operation-type-label';

export function OperationTypeSelect({
  accountKind,
  onChange,
  value,
}: {
  accountKind?: string;
  onChange: (type: PortfolioOperationType) => void;
  value: PortfolioOperationType;
}) {
  const { t } = useTranslation();
  return (
    <OperationField label={t('portfolio.account.operationTypeField')}>
      <select
        data-autofocus
        value={value}
        onChange={(event) => onChange(event.target.value as PortfolioOperationType)}
      >
        {allowedOperationTypes(accountKind).map((type) => (
          <option key={type} value={type}>
            {operationTypeLabel(t, type)}
          </option>
        ))}
      </select>
    </OperationField>
  );
}
