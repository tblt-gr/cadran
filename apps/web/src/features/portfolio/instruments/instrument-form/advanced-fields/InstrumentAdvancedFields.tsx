import { FormSection } from '@/components/ui/form-layout/FormSection';
import type { InstrumentFieldErrors } from '@/features/portfolio/instruments/instrumentError';
import { InstrumentPrecisionField } from '@/features/portfolio/instruments/instrument-form/precision-field/InstrumentPrecisionField';
import { InstrumentSelectField } from '@/features/portfolio/instruments/instrument-form/select-field/InstrumentSelectField';
import {
  instrumentClasses,
  instrumentRegions,
  validPrecision,
} from '@/features/portfolio/instruments/instrument-form/instrumentOptions';

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
  return (
    <FormSection
      title="Champs avancés"
      open={
        advancedOpen ||
        Boolean(fieldErrors.venueMic) ||
        (showErrors && (!validPrecision(quantityPrecision) || !validPrecision(pricePrecision)))
      }
      onToggle={setAdvancedOpen}
    >
      {!euroFund ? (
        <label>
          <span>Place de cotation (MIC)</span>
          <input
            maxLength={4}
            onChange={(e) => setVenue(e.target.value.toUpperCase())}
            value={venueMic}
          />
          {fieldErrors.venueMic ? <small>{fieldErrors.venueMic}</small> : null}
        </label>
      ) : null}
      <InstrumentSelectField
        label="Classe d'actifs"
        nullable
        options={instrumentClasses}
        onChange={setClass}
        value={assetClass}
      />
      <InstrumentSelectField
        label="Région"
        nullable
        options={instrumentRegions}
        onChange={setRegion}
        value={region}
      />
      {euroFund ? (
        <>
          <label>
            Précision des quantités
            <input readOnly value={euroPrecision ?? ''} />
          </label>
          <label>
            Précision des prix
            <input readOnly value="0" />
          </label>
          <p>Un fonds en euros vaut toujours 1 € par part.</p>
        </>
      ) : (
        <>
          <InstrumentPrecisionField
            label="Précision des quantités"
            locked={locked}
            onChange={setQuantityPrecision}
            showErrors={showErrors}
            value={quantityPrecision}
          />
          <InstrumentPrecisionField
            label="Précision des prix"
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
