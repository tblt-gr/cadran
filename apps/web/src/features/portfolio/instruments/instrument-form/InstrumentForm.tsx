import type {
  CreateInstrumentRequest,
  Instrument,
  InstrumentAssetClass,
  InstrumentRegion,
  InstrumentType,
  UpdateInstrumentRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useReferenceAssets } from '@/hooks/use-reference-assets';
import type { InstrumentFieldErrors } from '@/features/portfolio/instruments/instrumentError';
import styles from './InstrumentForm.module.css';

interface Props {
  existingInstrumentId?: string | null;
  instrument?: Instrument;
  pending: boolean;
  error?: string;
  fieldErrors?: InstrumentFieldErrors;
  onSubmit: (body: CreateInstrumentRequest | UpdateInstrumentRequest) => void;
}

const types = [
  ['EQUITY', 'Action'],
  ['ETF', 'ETF'],
  ['FUND', 'Fonds (OPCVM)'],
  ['BOND', 'Obligation'],
  ['OTHER', 'Autre'],
] as const;
const classes = [
  ['EQUITY', 'Actions'],
  ['BOND', 'Obligations'],
  ['MONEY_MARKET', 'Monétaire'],
  ['REAL_ESTATE', 'Immobilier'],
  ['COMMODITY', 'Matières premières'],
  ['MIXED', 'Diversifié'],
  ['OTHER', 'Autre'],
] as const;
const regions = [
  ['FRANCE', 'France'],
  ['EUROPE', 'Europe'],
  ['NORTH_AMERICA', 'Amérique du Nord'],
  ['ASIA_PACIFIC', 'Asie-Pacifique'],
  ['EMERGING', 'Pays émergents'],
  ['WORLD', 'Monde'],
  ['OTHER', 'Autre'],
] as const;

const PRECISION_MESSAGE = 'La précision doit être comprise entre 0 et 12.';
function validPrecision(value: string) {
  return /^\d{1,2}$/.test(value) && Number(value) <= 12;
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
  const locked = instrument?.used ?? false;
  const invalid =
    name.trim() === '' ||
    (!isin.trim() && !ticker.trim()) ||
    !validPrecision(quantityPrecision) ||
    !validPrecision(pricePrecision);
  const existingHref = `/portfolios/instruments?q=${encodeURIComponent(isin.trim())}&status=all`;
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
      isin: optional(isin),
      ticker: optional(ticker),
      venueMic: optional(venueMic),
      instrumentType,
      assetClass: (assetClass || null) as InstrumentAssetClass | null,
      region: (region || null) as InstrumentRegion | null,
      currencyCode,
      quantityPrecision: Number(quantityPrecision),
      pricePrecision: Number(pricePrecision),
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
        <label>
          <span>ISIN</span>
          <input
            aria-describedby={existingInstrumentId ? 'existing-instrument' : undefined}
            aria-invalid={existingInstrumentId ? true : undefined}
            disabled={locked}
            maxLength={12}
            onChange={(e) => setIsin(e.target.value.toUpperCase())}
            value={isin}
          />
          {existingInstrumentId ? (
            <small id="existing-instrument">
              Cet ISIN existe déjà.{' '}
              <a
                href={existingHref}
                onClick={(event) => handleClientNavigation(event, existingHref)}
              >
                Voir l’instrument existant
              </a>
            </small>
          ) : fieldErrors.isin ? (
            <small>{fieldErrors.isin}</small>
          ) : locked ? (
            <small>Figé après la première opération</small>
          ) : null}
        </label>
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
        <Select
          label="Type"
          options={types}
          onChange={(value) => setType(value as InstrumentType)}
          value={instrumentType}
        />
        <Select
          label="Classe d'actifs"
          nullable
          options={classes}
          onChange={setClass}
          value={assetClass}
        />
        <Select label="Région" nullable options={regions} onChange={setRegion} value={region} />
        <label>
          <span>Devise</span>
          <select
            disabled={locked || assets.isPending || assets.isError}
            onChange={(e) => setCurrency(e.target.value)}
            value={currencyCode}
          >
            {fiat.map((asset) => (
              <option key={asset.code} value={asset.code}>
                {asset.code}
              </option>
            ))}
          </select>
          {locked ? <small>Figé après la première opération</small> : null}
        </label>
        <label>
          <span>Précision des quantités</span>
          <input
            disabled={locked}
            inputMode="numeric"
            onChange={(e) => setQuantityPrecision(e.target.value)}
            value={quantityPrecision}
          />
          {showErrors && !validPrecision(quantityPrecision) ? (
            <small>{PRECISION_MESSAGE}</small>
          ) : null}
          {locked ? <small>Figé après la première opération</small> : null}
        </label>
        <label>
          <span>Précision des prix</span>
          <input
            disabled={locked}
            inputMode="numeric"
            onChange={(e) => setPricePrecision(e.target.value)}
            value={pricePrecision}
          />
          {showErrors && !validPrecision(pricePrecision) ? (
            <small>{PRECISION_MESSAGE}</small>
          ) : null}
          {locked ? <small>Figé après la première opération</small> : null}
        </label>
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending || assets.isError} type="submit">
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  );
}

function Select({
  label,
  options,
  onChange,
  value,
  nullable = false,
}: {
  label: string;
  options: readonly (readonly [string, string])[];
  onChange: (value: string) => void;
  value: string;
  nullable?: boolean;
}) {
  return (
    <label>
      <span>{label}</span>
      <select onChange={(e) => onChange(e.target.value)} value={value}>
        {nullable ? <option value="">Non renseignée</option> : null}
        {options.map(([id, text]) => (
          <option key={id} value={id}>
            {text}
          </option>
        ))}
      </select>
    </label>
  );
}
