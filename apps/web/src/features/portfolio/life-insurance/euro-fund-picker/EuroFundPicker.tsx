import { useState } from 'react';
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
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const query = useQuery({
    queryKey: ['euro-fund-picker', assetCode, q, page],
    retry: false,
    queryFn: async ({ signal }) => {
      const result = await listInstruments({
        ...authApiOptions(),
        query: { currencyCode: assetCode, q: q || undefined, status: 'all', perPage: 100, page },
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
        Rechercher un fonds en euros
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
          <SkeletonField label="Support" />
          <SkeletonStatus>Chargement des supports…</SkeletonStatus>
        </div>
      ) : query.isError ? (
        <p role="alert">
          Les supports sont indisponibles.{' '}
          <button type="button" className="secondary-action" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </p>
      ) : (
        <>
          <label>
            Support
            <select
              required
              value={value}
              onChange={(e) => onChange(e.target.value)}
              disabled={disabled}
            >
              <option value="">Choisir un fonds en euros</option>
              {funds.map((i) => (
                <option key={i.id} value={i.id}>
                  {i.name}
                  {i.status === 'INACTIVE' ? ' (inactif)' : ''}
                </option>
              ))}
            </select>
          </label>
          {funds.length === 0 ? (
            <p>
              Aucun fonds en euros trouvé sur cette page. Recherchez son nom ou consultez la page
              suivante.
            </p>
          ) : null}
        </>
      )}
      {query.data && (query.data.total > 100 || page > 1) ? (
        <div className={styles.actions}>
          <button
            className="secondary-action"
            type="button"
            disabled={page === 1 || disabled}
            onClick={() => {
              setPage((p) => p - 1);
              onChange('');
            }}
          >
            Précédent
          </button>
          <button
            className="secondary-action"
            type="button"
            disabled={page * 100 >= query.data.total || disabled}
            onClick={() => {
              setPage((p) => p + 1);
              onChange('');
            }}
          >
            Suivant
          </button>
        </div>
      ) : null}
    </div>
  );
}
