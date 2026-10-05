import { useState } from 'react';
import type {
  LifeInsuranceEuroFundRateInput,
  LifeInsuranceEuroFundRateBasis,
} from '@cadran/api-client';
import { basisLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import { EuroFundPicker } from '@/features/portfolio/life-insurance/euro-fund-picker/EuroFundPicker';
import styles from '@/features/portfolio/life-insurance/contract-form/ContractForm.module.css';
export function EuroFundRateForm({
  assetCode,
  subscribedOn,
  today,
  pending,
  error,
  onSubmit,
}: {
  assetCode: string;
  subscribedOn: string;
  today: string;
  pending: boolean;
  error?: string;
  onSubmit: (input: LifeInsuranceEuroFundRateInput) => void;
}) {
  const [instrumentId, setInstrumentId] = useState('');
  const [year, setYear] = useState(String(Number(today.slice(0, 4)) - 1));
  const [servedRate, setServedRate] = useState('');
  const [bonusRate, setBonusRate] = useState('');
  const [basis, setBasis] = useState<LifeInsuranceEuroFundRateBasis>('UNKNOWN');
  const [sourceNote, setSourceNote] = useState('');
  return (
    <form
      className={styles.form}
      onSubmit={(e) => {
        e.preventDefault();
        if (!instrumentId) return;
        onSubmit({
          instrumentId,
          year: Number(year),
          servedRate: servedRate.replace(',', '.'),
          bonusRate: bonusRate ? bonusRate.replace(',', '.') : null,
          basis,
          sourceNote: sourceNote.trim() || null,
        });
      }}
    >
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <EuroFundPicker
        assetCode={assetCode}
        value={instrumentId}
        onChange={setInstrumentId}
        disabled={pending}
      />
      <div className={styles.fields}>
        <label>
          Année
          <input
            data-autofocus
            type="number"
            required
            min={subscribedOn.slice(0, 4)}
            max={today.slice(0, 4)}
            value={year}
            onChange={(e) => setYear(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Taux servi (%)
          <input
            required
            inputMode="decimal"
            pattern="-?[0-9]+([.,][0-9]{1,4})?"
            value={servedRate}
            onChange={(e) => setServedRate(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Bonus (%)
          <input
            inputMode="decimal"
            pattern="[0-9]+([.,][0-9]{1,4})?"
            value={bonusRate}
            onChange={(e) => setBonusRate(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Base
          <select
            value={basis}
            onChange={(e) => setBasis(e.target.value as LifeInsuranceEuroFundRateBasis)}
            disabled={pending}
          >
            {Object.entries(basisLabels).map(([id, label]) => (
              <option key={id} value={id}>
                {label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Source
          <input
            maxLength={200}
            value={sourceNote}
            onChange={(e) => setSourceNote(e.target.value)}
            disabled={pending}
          />
        </label>
      </div>
      <div className={styles.actions}>
        <button className="primary-action" type="submit" disabled={pending || !instrumentId}>
          {pending ? 'Enregistrement…' : 'Enregistrer le taux'}
        </button>
      </div>
    </form>
  );
}
