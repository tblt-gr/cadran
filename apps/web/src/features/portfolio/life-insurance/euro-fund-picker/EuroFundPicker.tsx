import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useQuery } from '@tanstack/react-query';
import { listInstruments } from '@cadran/api-client';
import { authApiOptions } from '@/features/auth/apiOptions';
import { SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from '@/features/portfolio/life-insurance/contract-form/ContractForm.module.css';
export function EuroFundPicker({
  assetCode,
  value,
  onChange,
  disabled,
}: {
  assetCode: string;
  value: string;
  onChange: (value: string) => void;
  disabled: boolean;
}) {
  const { t } = useTranslation();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ['euro-fund-picker', assetCode, q, page],
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await listInstruments({
        ...authApiOptions(),
        query: { currencyCode: assetCode, q: q || undefined, status: 'all', pageSize: 50, page },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Euro fund search failed');
      return result.data;
    },
  });
  const funds = query.data?.items.filter((i) => i.instrumentType === 'EURO_FUND') ?? [];
  return (
    <div className={styles.form}>
      <label>
        {t('portfolio.lifeInsurance.rates.picker.search')}
        <input
          value={q}
          onChange={(e) => {
            setQ(e.target.value);
            setPage(1);
            onChange('');
          }}
          disabled={disabled}
        />
      </label>
      {query.isPending ? (
        <div aria-busy="true">
          <SkeletonField label={t('portfolio.lifeInsurance.rates.columns.support')} />
          <SkeletonStatus>{t('portfolio.lifeInsurance.rates.picker.loading')}</SkeletonStatus>
        </div>
      ) : query.isError ? (
        <p role="alert">
          {t('portfolio.lifeInsurance.rates.picker.unavailable')}{' '}
          <button type="button" className="secondary-action" onClick={() => void query.refetch()}>
            {t('actions.retry')}
          </button>
        </p>
      ) : (
        <>
          <label>
            {t('portfolio.lifeInsurance.rates.columns.support')}
            <select
              required
              value={value}
              onChange={(e) => onChange(e.target.value)}
              disabled={disabled}
            >
              <option value="">{t('portfolio.lifeInsurance.rates.picker.choose')}</option>
              {funds.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                  {i.status === 'INACTIVE'
                    ? t('portfolio.lifeInsurance.rates.picker.inactive')
                    : ''}
                </option>
              ))}
            </select>
          </label>
          {funds.length === 0 ? <p>{t('portfolio.lifeInsurance.rates.picker.none')}</p> : null}
        </>
      )}
      {query.data && (query.data.total > 50 || page > 1) ? (
        <ModalActions>
          <button
            className="secondary-action"
            type="button"
            disabled={page === 1 || disabled}
            onClick={() => {
              setPage((p) => p - 1);
              onChange('');
            }}
          >
            {t('portfolio.lifeInsurance.rates.picker.previous')}
          </button>
          <button
            className="secondary-action"
            type="button"
            disabled={page * 50 >= query.data.total || disabled}
            onClick={() => {
              setPage((p) => p + 1);
              onChange('');
            }}
          >
            {t('portfolio.lifeInsurance.rates.picker.next')}
          </button>
        </ModalActions>
      ) : null}
    </div>
  );
}
