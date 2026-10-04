import { InstrumentPicker } from '@/features/portfolio/instrument-picker/InstrumentPicker';
import styles from './OperationInstrumentField.module.css';

type Selection = { id: string; name: string } | null;

/** Instrument picker with its inline error, used for both the traded and the received instrument. */
export function OperationInstrumentField({
  currencyCode,
  error,
  label,
  onChange,
  value,
}: {
  currencyCode: string;
  error?: string;
  label: string;
  onChange: (value: Selection) => void;
  value: Selection;
}) {
  return (
    <div className={styles.picker}>
      <InstrumentPicker
        currencyCode={currencyCode}
        label={label}
        onChange={(item) => onChange(item ? { id: item.id, name: item.name } : null)}
        value={value}
      />
      {error ? (
        <small className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </div>
  );
}
