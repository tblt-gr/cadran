import type { PortfolioOperationType } from '@cadran/api-client';
import { MultiSelect } from '@/components/ui/multi-select/MultiSelect';
import {
  operationTypeLabel,
  operationTypes,
  allowedOperationTypes,
} from '@/features/portfolio/portfolio-account/operations/operation-type-label';

export function OperationTypeSelect({
  onChange,
  accountKind,
  value,
}: {
  accountKind?: string;
  onChange: (types: PortfolioOperationType[]) => void;
  value: readonly PortfolioOperationType[];
}) {
  return (
    <MultiSelect
      label="Types d’opération"
      value={value}
      onChange={onChange}
      options={(accountKind ? allowedOperationTypes(accountKind) : operationTypes).map((type) => ({
        value: type,
        label: operationTypeLabel(type),
      }))}
    />
  );
}
