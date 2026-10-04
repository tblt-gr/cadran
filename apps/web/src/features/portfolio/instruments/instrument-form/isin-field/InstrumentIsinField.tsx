import { handleClientNavigation } from '@/hooks/use-client-navigation';

export function InstrumentIsinField({
  error,
  existingInstrumentId,
  locked,
  onChange,
  value,
}: {
  error?: string;
  existingInstrumentId?: string | null;
  locked: boolean;
  onChange: (value: string) => void;
  value: string;
}) {
  const existingHref = `/portfolios/instruments?q=${encodeURIComponent(value.trim())}&status=all`;
  return (
    <label>
      <span>ISIN</span>
      <input
        aria-describedby={existingInstrumentId ? 'existing-instrument' : undefined}
        aria-invalid={existingInstrumentId ? true : undefined}
        disabled={locked}
        maxLength={12}
        onChange={(e) => onChange(e.target.value.toUpperCase())}
        value={value}
      />
      {existingInstrumentId ? (
        <small id="existing-instrument">
          Cet ISIN existe déjà.{' '}
          <a href={existingHref} onClick={(event) => handleClientNavigation(event, existingHref)}>
            Voir l’instrument existant
          </a>
        </small>
      ) : error ? (
        <small>{error}</small>
      ) : locked ? (
        <small>Figé après la première opération</small>
      ) : null}
    </label>
  );
}
