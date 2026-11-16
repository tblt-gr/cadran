import type { PortfolioOperationType } from '@cadran/api-client';
import { MultiSelect } from '@/components/ui/multi-select/MultiSelect';
import {
  operationTypeLabel,
  operationTypes,
  allowedOperationTypes,
} from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import styles from './OperationTypeSelect.module.css';

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
    <div className={styles.types}>
      <MultiSelect
        label="Types d’opération"
        value={value}
        onChange={onChange}
        options={(accountKind ? allowedOperationTypes(accountKind) : operationTypes).map(
          (type) => ({
            value: type,
            label: operationTypeLabel(type),
          }),
        )}
      />
    </div>
  );
}
