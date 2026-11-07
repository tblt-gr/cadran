import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useWorkspaceBaseCurrency } from '@/features/auth/useWorkspaceBaseCurrency';
import type {
  CreateInstrumentRequest,
  Instrument,
  InstrumentAssetClass,
  InstrumentRegion,
  InstrumentType,
  UpdateInstrumentRequest,
} from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useReferenceAssets } from '@/hooks/use-reference-assets';
import type { InstrumentFieldErrors } from '@/features/investments/instruments/instrumentError';
import { InstrumentIsinField } from './isin-field/InstrumentIsinField';
import { InstrumentSelectField } from './select-field/InstrumentSelectField';
import { instrumentTypes, validPrecision } from './instrumentOptions';
import styles from './InstrumentForm.module.css';
import { InstrumentAdvancedFields } from './advanced-fields/InstrumentAdvancedFields';

export interface InstrumentInitial {
  name?: string;
  isin?: string;
  instrumentType?: InstrumentType;
  currencyCode?: string;
}
export interface InstrumentLock {
  currencyCode?: boolean;
  instrumentType?: boolean;
}

interface Props {
  initial?: InstrumentInitial;
  lock?: InstrumentLock;
  existingInstrumentId?: string | null;
  instrument?: Instrument;
  pending: boolean;
  error?: ReactNode;
  fieldErrors?: InstrumentFieldErrors;
  onSubmit: (body: CreateInstrumentRequest | UpdateInstrumentRequest) => void;
}

export function InstrumentForm({
  existingInstrumentId,
  initial,
  lock,
  instrument,
  pending,
  error,
  fieldErrors = {},
  onSubmit,
}: Props) {
  const assets = useReferenceAssets();
  const baseCurrency = useWorkspaceBaseCurrency();
  const [name, setName] = useState(instrument?.name ?? initial?.name ?? '');
  const [isin, setIsin] = useState(instrument?.isin ?? initial?.isin ?? '');
  const [ticker, setTicker] = useState(instrument?.ticker ?? '');
  const [venueMic, setVenue] = useState(instrument?.venueMic ?? '');
  const [instrumentType, setType] = useState(
    instrument?.instrumentType ?? initial?.instrumentType ?? 'ETF',
  );
  const [assetClass, setClass] = useState(instrument?.assetClass ?? '');
  const [region, setRegion] = useState(instrument?.region ?? '');
  const [currencyCode, setCurrency] = useState(
    instrument?.currencyCode ?? initial?.currencyCode ?? 'EUR',
  );
  const [quantityPrecision, setQuantityPrecision] = useState(
    String(instrument?.quantityPrecision ?? 6),
  );
  const [pricePrecision, setPricePrecision] = useState(String(instrument?.pricePrecision ?? 4));
  const [advancedOpen, setAdvancedOpen] = useState(
    Boolean(
      instrument?.venueMic ||
      instrument?.assetClass ||
      instrument?.region ||
      (instrument && (instrument.quantityPrecision !== 6 || instrument.pricePrecision !== 4)),
    ),
  );
  const [showErrors, setShowErrors] = useState(false);
  const euroFund = instrumentType === 'EURO_FUND';
  const incompatibleEuroCurrency = Boolean(lock?.currencyCode && currencyCode !== baseCurrency);
  const invalidEuroCurrency = euroFund && incompatibleEuroCurrency;
  const effectiveCurrency = lock?.currencyCode
    ? currencyCode
    : euroFund
      ? baseCurrency
      : currencyCode;
  const euroPrecision = assets.data?.items.find(
    (asset) => asset.code === baseCurrency,
  )?.displayPrecision;
  const locked = instrument?.used ?? false;
  const invalid =
    invalidEuroCurrency ||
    name.trim() === '' ||
    (!euroFund && !isin.trim() && !ticker.trim()) ||
    !validPrecision(quantityPrecision) ||
    !validPrecision(pricePrecision);
  const fiat = (assets.data?.items ?? []).filter((asset) => asset.kind === 'FIAT');

  function optional(value: string) {
    return value.trim() || null;
  }
  function submit(event: React.FormEvent) {
    event.preventDefault();
    event.stopPropagation();
    if (invalid) {
      setShowErrors(true);
      return;
    }
    const body = {
      name: name.trim(),
      isin: euroFund ? null : optional(isin),
      ticker: euroFund ? null : optional(ticker),
      venueMic: euroFund ? null : optional(venueMic),
      instrumentType,
      assetClass: (assetClass || null) as InstrumentAssetClass | null,
      region: (region || null) as InstrumentRegion | null,
      currencyCode: effectiveCurrency,
      quantityPrecision: euroFund ? euroPrecision! : Number(quantityPrecision),
      pricePrecision: euroFund ? 0 : Number(pricePrecision),
    };
    onSubmit(
      instrument
        ? ({ ...body, version: instrument.version } as UpdateInstrumentRequest)
        : (body as CreateInstrumentRequest),
    );
  }
  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {error ? (
        <div className={styles.alert} role="alert">
          {error}
        </div>
      ) : null}
      <FormGrid>
        <label>
          <span>Nom</span>
          <input
            data-autofocus={!initial?.name || euroFund ? true : undefined}
            maxLength={120}
            onChange={(e) => setName(e.target.value)}
            value={name}
          />
          {showErrors && name.trim() === '' ? <small>Le nom est obligatoire.</small> : null}
        </label>
        {!euroFund ? (
          <>
            <InstrumentIsinField
              autofocus={Boolean(initial?.name)}
              error={fieldErrors.isin}
              existingInstrumentId={existingInstrumentId}
              locked={locked}
              onChange={setIsin}
              value={isin}
            />
            <label>
              <span>Ticker</span>
              <input
                maxLength={12}
                onChange={(e) => setTicker(e.target.value.toUpperCase())}
                value={ticker}
              />
              {showErrors && !isin.trim() && !ticker.trim() ? (
                <small>Un ISIN ou un ticker est obligatoire.</small>
              ) : fieldErrors.ticker ? (
                <small>{fieldErrors.ticker}</small>
              ) : null}
            </label>
          </>
        ) : null}
        <InstrumentSelectField
          disabled={lock?.instrumentType}
          disabledOptions={incompatibleEuroCurrency ? ['EURO_FUND'] : undefined}
          label="Type"
          options={instrumentTypes}
          onChange={(value) => setType(value as InstrumentType)}
          value={instrumentType}
        />
        <label>
          <span>Devise</span>
          <select
            aria-label="Devise"
            disabled={
              lock?.currencyCode || euroFund || locked || assets.isPending || assets.isError
            }
            onChange={(e) => setCurrency(e.target.value)}
            value={effectiveCurrency}
          >
            {fiat.map((asset) => (
              <option key={asset.code} value={asset.code}>
                {asset.code}
              </option>
            ))}
          </select>
          {lock?.currencyCode ? (
            <small>Devise du compte</small>
          ) : locked ? (
            <small>Figé après la première utilisation</small>
          ) : null}
        </label>
        <InstrumentAdvancedFields
          advancedOpen={advancedOpen}
          setAdvancedOpen={setAdvancedOpen}
          fieldErrors={fieldErrors}
          showErrors={showErrors}
          euroFund={euroFund}
          euroPrecision={euroPrecision}
          locked={locked}
          venueMic={venueMic}
          setVenue={setVenue}
          assetClass={assetClass}
          setClass={setClass}
          region={region}
          setRegion={setRegion}
          quantityPrecision={quantityPrecision}
          setQuantityPrecision={setQuantityPrecision}
          pricePrecision={pricePrecision}
          setPricePrecision={setPricePrecision}
        />
      </FormGrid>
      {invalidEuroCurrency ? (
        <p role="alert">Un fonds en euros doit utiliser la devise de référence de l’espace.</p>
      ) : null}
      <ModalActions>
        <button
          className="primary-action"
          disabled={
            pending ||
            invalidEuroCurrency ||
            assets.isError ||
            (euroFund && (assets.isPending || euroPrecision === undefined))
          }
          type="submit"
        >
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </ModalActions>
    </form>
  );
}
