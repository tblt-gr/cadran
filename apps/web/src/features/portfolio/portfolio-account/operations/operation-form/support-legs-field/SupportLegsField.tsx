import type {
  OperationFieldErrors,
  SupportLegValue,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';
import { SupportLegRow } from './SupportLegRow';
import styles from './SupportLegsField.module.css';

export function SupportLegsField({
  assetCode,
  label,
  maxLegs = 20,
  indexOffset = 0,
  errors,
  onChange,
  value,
}: {
  assetCode: string;
  label?: string;
  maxLegs?: number;
  indexOffset?: number;
  errors: OperationFieldErrors;
  onChange: (value: SupportLegValue[]) => void;
  value: SupportLegValue[];
}) {
  return (
    <div className={styles.editor} role={label ? 'group' : undefined} aria-label={label}>
      {label ? <h3>{label}</h3> : null}
      {value.length === 0 ? (
        <p>
          {label
            ? 'Ajoutez au moins un support.'
            : 'Aucun support : l’opération utilise les liquidités du contrat.'}
        </p>
      ) : null}
      {value.map((leg, index) => (
        <SupportLegRow
          key={leg.key}
          assetCode={assetCode}
          errors={errors}
          index={index + 1 + indexOffset}
          value={leg}
          onChange={(next) =>
            onChange(value.map((current) => (current.key === leg.key ? next : current)))
          }
          onRemove={() => onChange(value.filter((current) => current.key !== leg.key))}
        />
      ))}
      {errors.legs ? <p role="alert">{errors.legs}</p> : null}
      <button
        className="secondary-action"
        disabled={value.length >= maxLegs}
        type="button"
        onClick={() =>
          onChange([
            ...value,
            { key: crypto.randomUUID(), instrument: null, quantity: '', unitPrice: '', amount: '' },
          ])
        }
      >
        Ajouter un support
      </button>
    </div>
  );
}
