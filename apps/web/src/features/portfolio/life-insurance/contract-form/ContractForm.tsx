import { useState } from 'react';
import type {
  LifeInsuranceContract,
  LifeInsuranceContractInput,
  LifeInsuranceManagementMode,
} from '@cadran/api-client';
import { managementLabels } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import styles from './ContractForm.module.css';
export function ContractForm({
  contract,
  today,
  pending,
  error,
  onSubmit,
}: {
  contract?: LifeInsuranceContract;
  today: string;
  pending: boolean;
  error?: string;
  onSubmit: (input: LifeInsuranceContractInput) => void;
}) {
  const [insurer, setInsurer] = useState(contract?.insurer ?? '');
  const [distributor, setDistributor] = useState(contract?.distributor ?? '');
  const [commercialName, setCommercialName] = useState(contract?.commercialName ?? '');
  const [number, setNumber] = useState('');
  const [clearNumber, setClearNumber] = useState(false);
  const [subscribedOn, setSubscribedOn] = useState(contract?.subscribedOn ?? '');
  const [taxStartOn, setTaxStartOn] = useState(contract?.taxStartOn ?? '');
  const [mode, setMode] = useState<LifeInsuranceManagementMode>(contract?.managementMode ?? 'FREE');
  return (
    <form
      className={styles.form}
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit({
          insurer: insurer.trim(),
          distributor: distributor.trim() || null,
          commercialName: commercialName.trim() || null,
          subscribedOn,
          taxStartOn: taxStartOn || subscribedOn,
          managementMode: mode,
          ...(contract ? { version: contract.version } : {}),
          ...(clearNumber
            ? { contractNumber: null }
            : number.trim()
              ? { contractNumber: number.trim() }
              : {}),
        });
      }}
    >
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <div className={styles.fields}>
        <label>
          Assureur
          <input
            data-autofocus
            required
            maxLength={80}
            value={insurer}
            onChange={(e) => setInsurer(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Distributeur
          <input
            maxLength={80}
            value={distributor}
            onChange={(e) => setDistributor(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Nom commercial
          <input
            maxLength={120}
            value={commercialName}
            onChange={(e) => setCommercialName(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Numéro de contrat
          <input
            aria-label="Numéro de contrat"
            aria-describedby={contract ? 'contract-number-hint' : undefined}
            autoComplete="off"
            minLength={4}
            maxLength={34}
            pattern="[A-Za-z0-9 -]{4,34}"
            value={number}
            onChange={(e) => setNumber(e.target.value)}
            disabled={pending || clearNumber}
          />
          {contract ? (
            <small id="contract-number-hint">
              Actuel : {contract.contractNumberMasked ?? 'non renseigné'}. Laissez vide pour le
              conserver.
            </small>
          ) : null}
        </label>
        <label>
          Souscrit le
          <input
            type="date"
            required
            max={today}
            value={subscribedOn}
            onChange={(e) => setSubscribedOn(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Date fiscale du contrat
          <input
            type="date"
            max={today}
            value={taxStartOn}
            onChange={(e) => setTaxStartOn(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Mode de gestion
          <select
            value={mode}
            onChange={(e) => setMode(e.target.value as LifeInsuranceManagementMode)}
            disabled={pending}
          >
            {Object.entries(managementLabels).map(([id, label]) => (
              <option key={id} value={id}>
                {label}
              </option>
            ))}
          </select>
        </label>
      </div>
      {contract ? (
        <label className={styles.check}>
          <input
            type="checkbox"
            checked={clearNumber}
            onChange={(e) => setClearNumber(e.target.checked)}
            disabled={pending}
          />
          Effacer le numéro
        </label>
      ) : null}
      <div className={styles.actions}>
        <button className="primary-action" type="submit" disabled={pending}>
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  );
}
