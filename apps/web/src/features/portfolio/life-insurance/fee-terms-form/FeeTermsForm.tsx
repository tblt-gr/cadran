import { useState } from 'react';
import type { LifeInsuranceFeeTermsInput } from '@cadran/api-client';
import styles from '@/features/portfolio/life-insurance/contract-form/ContractForm.module.css';
const rates = [
  ['entryFeeRate', 'Versement (%)'],
  ['euroFundManagementFeeRate', 'Gestion fonds euros (%)'],
  ['unitLinkedManagementFeeRate', 'Gestion UC (%)'],
  ['switchFeeRate', 'Arbitrage (%)'],
] as const;
export function FeeTermsForm({
  assetCode,
  subscribedOn,
  pending,
  error,
  onSubmit,
}: {
  assetCode: string;
  subscribedOn: string;
  pending: boolean;
  error?: string;
  onSubmit: (input: LifeInsuranceFeeTermsInput) => void;
}) {
  const [values, setValues] = useState<Record<string, string>>({ validFrom: subscribedOn });
  const field = (name: string, value: string) => setValues((old) => ({ ...old, [name]: value }));
  const decimal = (name: string) => values[name]?.trim().replace(',', '.') || null;
  return (
    <form
      className={styles.form}
      onSubmit={(e) => {
        e.preventDefault();
        onSubmit({
          validFrom: values.validFrom!,
          entryFeeRate: decimal('entryFeeRate'),
          euroFundManagementFeeRate: decimal('euroFundManagementFeeRate'),
          unitLinkedManagementFeeRate: decimal('unitLinkedManagementFeeRate'),
          switchFeeRate: decimal('switchFeeRate'),
          switchFeeFixed: decimal('switchFeeFixed')
            ? { value: decimal('switchFeeFixed')!, assetCode }
            : null,
          sourceNote: values.sourceNote?.trim() || null,
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
          À partir du
          <input
            data-autofocus
            type="date"
            required
            min={subscribedOn}
            value={values.validFrom}
            onChange={(e) => field('validFrom', e.target.value)}
            disabled={pending}
          />
        </label>
        {rates.map(([id, label]) => (
          <label key={id}>
            {label}
            <input
              inputMode="decimal"
              pattern="[0-9]+([.,][0-9]{1,4})?"
              value={values[id] ?? ''}
              onChange={(e) => field(id, e.target.value)}
              disabled={pending}
            />
          </label>
        ))}
        <label>
          Arbitrage fixe ({assetCode})
          <input
            inputMode="decimal"
            pattern="[0-9]+([.,][0-9]+)?"
            value={values.switchFeeFixed ?? ''}
            onChange={(e) => field('switchFeeFixed', e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          Source
          <input
            maxLength={200}
            value={values.sourceNote ?? ''}
            onChange={(e) => field('sourceNote', e.target.value)}
            disabled={pending}
          />
        </label>
      </div>
      <div className={styles.actions}>
        <button className="primary-action" type="submit" disabled={pending}>
          {pending ? 'Enregistrement…' : 'Ajouter le barème'}
        </button>
      </div>
    </form>
  );
}
