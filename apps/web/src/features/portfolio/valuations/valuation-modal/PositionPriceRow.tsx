import type { PortfolioValuationDraftPosition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import type { PriceRowValues } from '@/features/portfolio/valuations/valuationRequest';
import { DecimalInput } from './DecimalInput';
import { FormField } from './FormField';
import styles from './ValuationForm.module.css';

function originHint(position: PortfolioValuationDraftPosition): string | null {
  const origin = position.lastPriceOrigin;
  if (!origin) return null;
  const date = formatCalendarNumericDay(origin.date);
  return origin.kind === 'VALUATION'
    ? `Prix de la valorisation du ${date}`
    : `Prix de l’achat du ${date}`;
}

/** One held instrument: read-only quantity, price prefilled from the draft, or declared unknown. */
export function PositionPriceRow({
  errors,
  index,
  onChange,
  position,
  value,
}: {
  errors: Record<string, string>;
  index: number;
  onChange: (value: PriceRowValues) => void;
  position: PortfolioValuationDraftPosition;
  value: PriceRowValues;
}) {
  const { i18n } = useTranslation();
  const hint = originHint(position);

  return (
    <fieldset className={styles.position}>
      <legend>{position.instrument.name ?? position.instrument.id}</legend>
      <div className={styles.field}>
        <span>Quantité</span>
        <strong>{formatDecimal(position.quantity, i18n.language)}</strong>
      </div>
      <DecimalInput
        disabled={value.unknown}
        error={errors[`/prices/${index}/price`]}
        hint={hint ?? undefined}
        label="Prix"
        onChange={(price) => onChange({ ...value, price })}
        value={value.unknown ? '' : value.price}
      />
      <FormField error={errors[`/prices/${index}/priceDate`]} label="Date du prix">
        {(attributes) => (
          <input
            {...attributes}
            disabled={value.unknown}
            onChange={(event) => onChange({ ...value, priceDate: event.target.value })}
            type="date"
            value={value.unknown ? '' : value.priceDate}
          />
        )}
      </FormField>
      <label className={styles.unknown}>
        <input
          checked={value.unknown}
          onChange={(event) => onChange({ ...value, unknown: event.target.checked })}
          type="checkbox"
        />
        Prix inconnu
      </label>
    </fieldset>
  );
}
