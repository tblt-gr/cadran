import type { PortfolioValuationDraftPosition } from '@cadran/api-client';
import { useId } from 'react';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { formatCalendarNumericDay, formatDecimal } from '@/lib/decimal';
import type { PriceRowValues } from '@/features/portfolio/valuations/valuationRequest';
import { DecimalInput } from './DecimalInput';
import { FormField } from './FormField';
import styles from './ValuationForm.module.css';

function originHint(position: PortfolioValuationDraftPosition, t: TFunction): string | null {
  const origin = position.lastPriceOrigin;
  if (!origin) return null;
  const date = formatCalendarNumericDay(origin.date);
  return origin.kind === 'VALUATION'
    ? t('portfolio.valuations.priceRow.fromValuation', { date })
    : t('portfolio.valuations.priceRow.fromPurchase', { date });
}

/**
 * One held instrument on one aligned row: what is held (name, quantity, where the prefilled
 * price comes from), then its price, the price date and the unknown-price escape.
 */
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
  const { t, i18n } = useTranslation();
  const nameId = useId();
  const hint = originHint(position, t);

  return (
    <div aria-labelledby={nameId} className={styles.position} role="group">
      <div className={styles.instrument}>
        <strong id={nameId}>{position.instrument.name ?? position.instrument.id}</strong>
        <small>
          {t('portfolio.valuations.priceRow.quantity', {
            quantity: formatDecimal(position.quantity, i18n.language),
          })}
        </small>
        {hint ? <small>{hint}</small> : null}
      </div>
      <DecimalInput
        disabled={value.unknown}
        error={errors[`/prices/${index}/price`]}
        label={t('portfolio.valuations.positions.price')}
        onChange={(price) => onChange({ ...value, price })}
        value={value.unknown ? '' : value.price}
      />
      <FormField
        error={errors[`/prices/${index}/priceDate`]}
        label={t('portfolio.valuations.positions.priceDate')}
      >
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
        {t('portfolio.valuations.positions.unknownPrice')}
      </label>
    </div>
  );
}
