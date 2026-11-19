import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import type { InstrumentFieldErrors } from '@/features/investments/instruments/instrumentError';
import { InstrumentPrecisionField } from '@/features/investments/instruments/instrument-form/precision-field/InstrumentPrecisionField';
import { InstrumentSelectField } from '@/features/investments/instruments/instrument-form/select-field/InstrumentSelectField';
import {
  instrumentClassOptions,
  instrumentRegionOptions,
  validPrecision,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';

interface Props {
  advancedOpen: boolean;
  setAdvancedOpen: (value: boolean) => void;
  fieldErrors: InstrumentFieldErrors;
  showErrors: boolean;
  euroFund: boolean;
  euroPrecision?: number;
  locked: boolean;
  venueMic: string;
  setVenue: (value: string) => void;
  assetClass: string;
  setClass: (value: string) => void;
  region: string;
  setRegion: (value: string) => void;
  quantityPrecision: string;
  setQuantityPrecision: (value: string) => void;
  pricePrecision: string;
  setPricePrecision: (value: string) => void;
}

/** Disclosure and validation visibility for optional metadata and precision controls. */
export function InstrumentAdvancedFields({
  advancedOpen,
  setAdvancedOpen,
  fieldErrors,
  showErrors,
  euroFund,
  euroPrecision,
  locked,
  venueMic,
  setVenue,
  assetClass,
  setClass,
  region,
  setRegion,
  quantityPrecision,
  setQuantityPrecision,
  pricePrecision,
  setPricePrecision,
}: Props) {
  const { t } = useTranslation();
  return (
    <FormSection
      title={t('transactions.form.advanced')}
      open={
        advancedOpen ||
        Boolean(fieldErrors.venueMic) ||
        (showErrors && (!validPrecision(quantityPrecision) || !validPrecision(pricePrecision)))
      }
      onToggle={setAdvancedOpen}
    >
      {!euroFund ? (
        <label>
          <span>{t('investments.instruments.form.venue')}</span>
          <input
            maxLength={4}
            onChange={(e) => setVenue(e.target.value.toUpperCase())}
            value={venueMic}
          />
          {fieldErrors.venueMic ? <small>{fieldErrors.venueMic}</small> : null}
        </label>
      ) : null}
      <InstrumentSelectField
        label={t('investments.instruments.form.assetClass')}
        nullable
        options={instrumentClassOptions(t)}
        onChange={setClass}
        value={assetClass}
      />
      <InstrumentSelectField
        label={t('investments.instruments.form.region')}
        nullable
        options={instrumentRegionOptions(t)}
        onChange={setRegion}
        value={region}
      />
      {euroFund ? (
        <>
          <label>
            {t('investments.instruments.form.quantityPrecision')}
            <input readOnly value={euroPrecision ?? ''} />
          </label>
          <label>
            {t('investments.instruments.form.pricePrecision')}
            <input readOnly value="0" />
          </label>
          <p>{t('investments.instruments.form.euroFundPar')}</p>
        </>
      ) : (
        <>
          <InstrumentPrecisionField
            label={t('investments.instruments.form.quantityPrecision')}
            locked={locked}
            onChange={setQuantityPrecision}
            showErrors={showErrors}
            value={quantityPrecision}
          />
          <InstrumentPrecisionField
            label={t('investments.instruments.form.pricePrecision')}
            locked={locked}
            onChange={setPricePrecision}
            showErrors={showErrors}
            value={pricePrecision}
          />
        </>
      )}
    </FormSection>
  );
}
