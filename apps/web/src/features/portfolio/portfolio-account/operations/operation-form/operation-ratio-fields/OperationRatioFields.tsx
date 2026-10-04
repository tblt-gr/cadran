import { OperationDecimalField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationDecimalField';
import styles from './OperationRatioFields.module.css';

export function OperationRatioFields({
  denominator,
  error,
  numerator,
  onDenominatorChange,
  onNumeratorChange,
}: {
  denominator: string;
  error?: string;
  numerator: string;
  onDenominatorChange: (value: string) => void;
  onNumeratorChange: (value: string) => void;
}) {
  return (
    <fieldset className={styles.ratio}>
      <legend>Ratio de division</legend>
      <OperationDecimalField
        label="Numérateur du ratio"
        onChange={onNumeratorChange}
        value={numerator}
      />
      <OperationDecimalField
        label="Dénominateur du ratio"
        onChange={onDenominatorChange}
        value={denominator}
      />
      {error ? (
        <small className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </fieldset>
  );
}
