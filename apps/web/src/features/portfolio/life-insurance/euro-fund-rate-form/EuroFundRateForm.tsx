import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useState } from 'react';
import type {
  LifeInsuranceEuroFundRateInput,
  LifeInsuranceEuroFundRateBasis,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { basisIds } from '@/features/portfolio/life-insurance/lifeInsuranceLabels';
import { EuroFundPicker } from '@/features/portfolio/life-insurance/euro-fund-picker/EuroFundPicker';
import styles from '@/features/portfolio/life-insurance/contract-form/ContractForm.module.css';
import { normalizeDecimalInput } from '@/lib/decimalInput';
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
  const { t } = useTranslation();
  const [instrumentId, setInstrumentId] = useState('');
  const [year, setYear] = useState(String(Number(today.slice(0, 4)) - 1));
  const [servedRate, setServedRate] = useState('');
  const [bonusRate, setBonusRate] = useState('');
  const [basis, setBasis] = useState<LifeInsuranceEuroFundRateBasis>('UNKNOWN');
  const [sourceNote, setSourceNote] = useState('');
  const [advancedOpen, setAdvancedOpen] = useState(false);
  return (
    <ModalForm
      className={styles.form}
      onSubmit={(e) => {
        e.preventDefault();
        if (!instrumentId) return;
        onSubmit({
          instrumentId,
          year: Number(year),
          servedRate: normalizeDecimalInput(servedRate),
          bonusRate: bonusRate ? normalizeDecimalInput(bonusRate) : null,
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
      <FormGrid>
        <label>
          {t('portfolio.lifeInsurance.rates.columns.year')}
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
          {t('portfolio.lifeInsurance.rates.form.served')}
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
          {t('portfolio.lifeInsurance.rates.form.bonus')}
          <input
            inputMode="decimal"
            pattern="[0-9]+([.,][0-9]{1,4})?"
            value={bonusRate}
            onChange={(e) => setBonusRate(e.target.value)}
            disabled={pending}
          />
        </label>
        <label>
          {t('portfolio.lifeInsurance.rates.columns.basis')}
          <select
            value={basis}
            onChange={(e) => setBasis(e.target.value as LifeInsuranceEuroFundRateBasis)}
            disabled={pending}
          >
            {basisIds.map((id) => (
              <option key={id} value={id}>
                {t(`portfolio.lifeInsurance.basis.${id}`)}
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
            {t('portfolio.lifeInsurance.fees.columns.source')}
            <input
              maxLength={200}
              value={sourceNote}
              onChange={(e) => setSourceNote(e.target.value)}
              disabled={pending}
            />
          </label>
        </FormSection>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" type="submit" disabled={pending || !instrumentId}>
          {pending
            ? t('portfolio.lifeInsurance.saving')
            : t('portfolio.lifeInsurance.rates.form.submit')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
