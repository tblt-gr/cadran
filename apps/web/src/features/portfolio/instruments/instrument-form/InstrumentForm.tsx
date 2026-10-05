import { useWorkspaceBaseCurrency } from '@/features/auth/useWorkspaceBaseCurrency';
import type {
  CreateInstrumentRequest,
  Instrument,
  InstrumentAssetClass,
  InstrumentRegion,
  InstrumentType,
  UpdateInstrumentRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useReferenceAssets } from '@/hooks/use-reference-assets';
import type { InstrumentFieldErrors } from '@/features/portfolio/instruments/instrumentError';
import { InstrumentIsinField } from './isin-field/InstrumentIsinField';
import { InstrumentPrecisionField } from './precision-field/InstrumentPrecisionField';
import { InstrumentSelectField } from './select-field/InstrumentSelectField';
import {
  instrumentClasses,
  instrumentRegions,
  instrumentTypes,
  validPrecision,
} from './instrumentOptions';
import styles from './InstrumentForm.module.css';

interface Props {
  existingInstrumentId?: string | null;
  instrument?: Instrument;
  pending: boolean;
  error?: string;
  fieldErrors?: InstrumentFieldErrors;
  onSubmit: (body: CreateInstrumentRequest | UpdateInstrumentRequest) => void;
}

export function InstrumentForm({
  existingInstrumentId,
  instrument,
  pending,
  error,
  fieldErrors = {},
  onSubmit,
}: Props) {
  const assets = useReferenceAssets();
  const baseCurrency = useWorkspaceBaseCurrency();
  const [name, setName] = useState(instrument?.name ?? '');
  const [isin, setIsin] = useState(instrument?.isin ?? '');
  const [ticker, setTicker] = useState(instrument?.ticker ?? '');
  const [venueMic, setVenue] = useState(instrument?.venueMic ?? '');
  const [instrumentType, setType] = useState(instrument?.instrumentType ?? 'ETF');
  const [assetClass, setClass] = useState(instrument?.assetClass ?? '');
  const [region, setRegion] = useState(instrument?.region ?? '');
  const [currencyCode, setCurrency] = useState(instrument?.currencyCode ?? 'EUR');
  const [quantityPrecision, setQuantityPrecision] = useState(
    String(instrument?.quantityPrecision ?? 6),
  );
  const [pricePrecision, setPricePrecision] = useState(String(instrument?.pricePrecision ?? 4));
  const [showErrors, setShowErrors] = useState(false);
  const euroFund = instrumentType === 'EURO_FUND';
  const euroPrecision = assets.data?.items.find(
    (asset) => asset.code === baseCurrency,
  )?.displayPrecision;
  const locked = instrument?.used ?? false;
  const invalid =
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
      currencyCode: euroFund ? baseCurrency : currencyCode,
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
        <p className={styles.alert} role="alert">
          {error}
        </p>
      ) : null}
      <div className={styles.fields}>
        <label>
          <span>Nom</span>
          <input
            data-autofocus
            maxLength={120}
            onChange={(e) => setName(e.target.value)}
            value={name}
          />
          {showErrors && name.trim() === '' ? <small>Le nom est obligatoire.</small> : null}
        </label>
        {!euroFund ? (
          <>
            <InstrumentIsinField
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
            <label>
              <span>Place de cotation (MIC)</span>
              <input
                maxLength={4}
                onChange={(e) => setVenue(e.target.value.toUpperCase())}
                value={venueMic}
              />
              {fieldErrors.venueMic ? <small>{fieldErrors.venueMic}</small> : null}
            </label>
          </>
        ) : null}
        <InstrumentSelectField
          label="Type"
          options={instrumentTypes}
          onChange={(value) => setType(value as InstrumentType)}
          value={instrumentType}
        />
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
        <label>
          <span>Devise</span>
          <select
            disabled={euroFund || locked || assets.isPending || assets.isError}
            onChange={(e) => setCurrency(e.target.value)}
            value={euroFund ? baseCurrency : currencyCode}
          >
            {fiat.map((asset) => (
              <option key={asset.code} value={asset.code}>
                {asset.code}
              </option>
            ))}
          </select>
          {locked ? <small>Figé après la première utilisation</small> : null}
        </label>
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
      </div>
      <div className={styles.actions}>
        <button
          className="primary-action"
          disabled={
            pending ||
            assets.isError ||
            (euroFund && (assets.isPending || euroPrecision === undefined))
          }
          type="submit"
        >
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  );
}
