import type { PortfolioTakeoverPreviewLine } from '@cadran/api-client';
import { Icon } from '@/components/ui/icon/Icon';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import { TakeoverInput } from './TakeoverInput';
import type { TakeoverLineField, TakeoverLineValues } from './takeoverRequest';
import styles from './PortfolioTakeoverForm.module.css';

/** One line held: what the owner types, then the value and unit cost the server computed. */
export function TakeoverLineRow({
  assetCode,
  errors,
  index,
  line,
  onChange,
  onRemove,
  preview,
  previewPending,
  removable,
}: {
  assetCode: string;
  errors: Partial<Record<TakeoverLineField, string>>;
  index: number;
  line: TakeoverLineValues;
  onChange: (patch: Partial<TakeoverLineValues>) => void;
  onRemove: () => void;
  /** The server figures of this row, absent while none is available. */
  preview: PortfolioTakeoverPreviewLine | undefined;
  previewPending: boolean;
  removable: boolean;
}) {
  const number = index + 1;
  const figure = (text: string | null) =>
    text !== null ? (
      <strong>{text}</strong>
    ) : previewPending ? (
      <strong>
        <SkeletonBlock className={styles.figure} />
      </strong>
    ) : (
      <strong>
        <EmptyValue label="Non calculable" />
      </strong>
    );
  return (
    <fieldset className={styles.row}>
      <legend>{`Ligne ${number}`}</legend>
      <div className={styles.instrument}>
        <InstrumentPicker
          allowCreate
          currencyCode={assetCode}
          label="Instrument"
          onChange={(item) =>
            onChange({ instrument: item ? { id: item.id, name: item.name } : null })
          }
          value={line.instrument}
        />
        {errors.instrument ? (
          <small className={styles.error} role="alert">
            {errors.instrument}
          </small>
        ) : null}
      </div>
      <TakeoverInput
        autoComplete="off"
        error={errors.quantity}
        inputMode="decimal"
        label="Quantité"
        onChange={(event) => onChange({ quantity: event.target.value })}
        value={line.quantity}
      />
      <TakeoverInput
        autoComplete="off"
        error={errors.costBasis}
        hint="Laissez vide si inconnu."
        inputMode="decimal"
        label="Coût de revient total"
        onChange={(event) => onChange({ costBasis: event.target.value })}
        value={line.costBasis}
      />
      <TakeoverInput
        autoComplete="off"
        error={errors.price}
        inputMode="decimal"
        label="Cours"
        onChange={(event) => onChange({ price: event.target.value })}
        value={line.price}
      />
      <TakeoverInput
        error={errors.priceDate}
        label="Date du cours"
        onChange={(event) => onChange({ priceDate: event.target.value })}
        type="date"
        value={line.priceDate}
      />
      <div className={styles.readout}>
        <span>Valeur</span>
        {figure(preview?.value ? formatAmount(preview.value, assetCode, 'fr') : null)}
      </div>
      <div className={styles.readout}>
        <span>PRU</span>
        {figure(
          preview?.averageUnitCost
            ? `${formatDecimal(preview.averageUnitCost, 'fr')} ${assetCode}`
            : null,
        )}
      </div>
      <button
        aria-label={`Retirer la ligne ${number}`}
        className={`icon-button ${styles.remove}`}
        disabled={!removable}
        onClick={onRemove}
        type="button"
      >
        <Icon name="delete" size={18} />
      </button>
    </fieldset>
  );
}
