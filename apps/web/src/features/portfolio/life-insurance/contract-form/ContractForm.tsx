import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
  const [insurer, setInsurer] = useState(contract?.insurer ?? '');
  const [distributor, setDistributor] = useState(contract?.distributor ?? '');
  const [commercialName, setCommercialName] = useState(contract?.commercialName ?? '');
  const [number, setNumber] = useState('');
  const [clearNumber, setClearNumber] = useState(false);
  const [subscribedOn, setSubscribedOn] = useState(contract?.subscribedOn ?? '');
  const [taxStartOn, setTaxStartOn] = useState(contract?.taxStartOn ?? '');
  const [mode, setMode] = useState<LifeInsuranceManagementMode>(contract?.managementMode ?? 'FREE');
  const [advancedOpen, setAdvancedOpen] = useState(
    Boolean(
      contract?.distributor ||
      contract?.commercialName ||
      contract?.contractNumberMasked ||
      (contract && contract.taxStartOn !== contract.subscribedOn),
    ),
  );
  return (
    <ModalForm
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
      <FormGrid>
        <label>
          {t('portfolio.lifeInsurance.contract.insurer')}
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
          {t('portfolio.lifeInsurance.contract.subscribedOn')}
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
          {t('portfolio.lifeInsurance.contract.managementMode')}
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
        <FormSection
          title={t('portfolio.lifeInsurance.advancedFields')}
          open={advancedOpen}
          onToggle={setAdvancedOpen}
        >
          <label>
            {t('portfolio.lifeInsurance.contract.distributor')}
            <input
              maxLength={80}
              value={distributor}
              onChange={(e) => setDistributor(e.target.value)}
              disabled={pending}
            />
          </label>
          <label>
            {t('portfolio.lifeInsurance.contract.commercialName')}
            <input
              maxLength={120}
              value={commercialName}
              onChange={(e) => setCommercialName(e.target.value)}
              disabled={pending}
            />
          </label>
          <label>
            {t('portfolio.lifeInsurance.contract.number')}
            <input
              aria-label={t('portfolio.lifeInsurance.contract.number')}
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
                {t('portfolio.lifeInsurance.contract.numberHint', {
                  current:
                    contract.contractNumberMasked ??
                    t('portfolio.lifeInsurance.contract.numberNone'),
                })}
              </small>
            ) : null}
          </label>
          <label>
            {t('portfolio.lifeInsurance.contract.taxStart')}
            <input
              type="date"
              max={today}
              value={taxStartOn}
              onChange={(e) => setTaxStartOn(e.target.value)}
              disabled={pending}
            />
          </label>
          {contract ? (
            <label className={styles.check}>
              <input
                type="checkbox"
                checked={clearNumber}
                onChange={(e) => setClearNumber(e.target.checked)}
                disabled={pending}
              />
              {t('portfolio.lifeInsurance.contract.clearNumber')}
            </label>
          ) : null}
        </FormSection>
      </FormGrid>

      <ModalActions>
        <button className="primary-action" type="submit" disabled={pending}>
          {pending ? t('portfolio.lifeInsurance.saving') : t('portfolio.lifeInsurance.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
