import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';

export function InstrumentIsinField({
  autofocus,
  error,
  existingInstrumentId,
  locked,
  onChange,
  value,
}: {
  autofocus?: boolean;
  error?: string;
  existingInstrumentId?: string | null;
  locked: boolean;
  onChange: (value: string) => void;
  value: string;
}) {
  const { t } = useTranslation();
  const existingHref = `/investments/instruments?q=${encodeURIComponent(value.trim())}&status=all`;
  return (
    <label>
      <span>ISIN</span>
      <input
        data-autofocus={autofocus || undefined}
        aria-describedby={existingInstrumentId ? 'existing-instrument' : undefined}
        aria-invalid={existingInstrumentId ? true : undefined}
        disabled={locked}
        maxLength={12}
        onChange={(e) => onChange(e.target.value.toUpperCase())}
        value={value}
      />
      {existingInstrumentId ? (
        <small id="existing-instrument">
          {t('investments.instruments.form.isinExists')}{' '}
          <a href={existingHref} onClick={(event) => handleClientNavigation(event, existingHref)}>
            {t('investments.instruments.form.viewExisting')}
          </a>
        </small>
      ) : error ? (
        <small>{error}</small>
      ) : locked ? (
        <small>{t('investments.instruments.locked')}</small>
      ) : null}
    </label>
  );
}
