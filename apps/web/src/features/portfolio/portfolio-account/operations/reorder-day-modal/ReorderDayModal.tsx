import { reorderPortfolioOperations } from '@cadran/api-client';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
import { Modal } from '@/components/ui/modal/Modal';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  OperationRequestError,
  operationRequestError,
  operationSaveMessage,
} from '@/features/portfolio/portfolio-account/operations/operationError';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './ReorderDayModal.module.css';
import { useDayOperations } from './useDayOperations';

interface Props {
  accountId: string;
  onClose: () => void;
  onSaved: () => void;
  tradeDate: string;
}

export function ReorderDayModal({ accountId, tradeDate, onClose, onSaved }: Props) {
  const client = useQueryClient();
  const day = useDayOperations(accountId, tradeDate);
  type DayItems = NonNullable<typeof day.data>;
  const [draft, setDraft] = useState<{ items: DayItems; source: DayItems } | null>(null);
  const [error, setError] = useState<string>();
  const [saving, setSaving] = useState(false);
  const items = draft && draft.source === day.data ? draft.items : (day.data ?? []);

  function move(index: number, offset: number) {
    const target = index + offset;
    if (target < 0 || target >= items.length) return;
    const next = [...items];
    const [item] = next.splice(index, 1);
    next.splice(target, 0, item!);
    setDraft({ items: next, source: day.data ?? items });
  }

  async function save() {
    setSaving(true);
    setError(undefined);
    try {
      const result = await withCsrfRetry(() =>
        reorderPortfolioOperations({
          ...authApiOptions(),
          path: { accountId },
          body: { tradeDate, operationIds: items.map((item) => item.id) },
        }),
      );
      if (!result.response?.ok) throw operationRequestError(result);

      await Promise.all([
        client.invalidateQueries({ queryKey: ['portfolio-summary', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-allocation', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-valuation-history', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-performance', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-performance-annual', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolios'] }),
        client.invalidateQueries({ queryKey: ['portfolio-operation-day', accountId, tradeDate] }),
        client.invalidateQueries({ queryKey: ['portfolio-operations', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-operation'] }),
        client.invalidateQueries({ queryKey: ['portfolio-positions', accountId] }),
        client.invalidateQueries({ queryKey: ['portfolio-position-lots', accountId] }),
      ]);
      onSaved();
      onClose();
    } catch (refusal) {
      if (refusal instanceof OperationRequestError && refusal.code === 'stale_operation_order') {
        setError('Les opérations de cette journée ont changé. L’ordre a été actualisé.');
        await day.refetch();
        setDraft(null);
        await Promise.all([
          client.invalidateQueries({ queryKey: ['portfolio-operations', accountId] }),
          client.invalidateQueries({ queryKey: ['portfolio-positions', accountId] }),
          client.invalidateQueries({ queryKey: ['portfolio-position-lots', accountId] }),
        ]);
        onSaved();
        return;
      }
      if (
        refusal instanceof OperationRequestError &&
        refusal.code === 'position_would_go_negative'
      ) {
        setError(
          `Cet ordre rendrait la position négative le ${formatCalendarNumericDay(tradeDate)}.`,
        );
      } else {
        setError(operationSaveMessage(refusal));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      close={onClose}
      title={`Réordonner la journée du ${formatCalendarNumericDay(tradeDate)}`}
    >
      <div className={styles.content}>
        {day.isPending ? (
          <section aria-busy="true" data-testid="reorder-day-skeleton">
            <SkeletonStatus>Chargement des opérations de la journée…</SkeletonStatus>
            <table aria-label="Opérations à réordonner">
              <thead>
                <tr>
                  <th scope="col">Opération</th>
                  <th scope="col">Ordre</th>
                </tr>
              </thead>
              <SkeletonTableRows columns={2} rows={3} />
            </table>
          </section>
        ) : day.isError ? (
          <div className={styles.errorState}>
            <p role="alert">Les opérations de cette journée sont indisponibles.</p>
            <button className="secondary-action" onClick={() => void day.refetch()} type="button">
              Réessayer
            </button>
          </div>
        ) : (
          <>
            {error ? (
              <p className={styles.error} role="alert">
                {error}
              </p>
            ) : null}
            <ol className={styles.list}>
              {items.map((item, index) => {
                const label = operationTypeLabel(item.type);
                return (
                  <li className={styles.item} key={item.id}>
                    <span className={styles.label}>{label}</span>
                    <button
                      aria-label={`Monter ${label}`}
                      className="icon-button"
                      disabled={index === 0 || saving}
                      onClick={() => move(index, -1)}
                      type="button"
                    >
                      <Icon name="arrow-up" />
                    </button>
                    <button
                      aria-label={`Descendre ${label}`}
                      className="icon-button"
                      disabled={index === items.length - 1 || saving}
                      onClick={() => move(index, 1)}
                      type="button"
                    >
                      <Icon name="arrow-down" />
                    </button>
                  </li>
                );
              })}
            </ol>
            <div className={styles.actions}>
              <button
                className="secondary-action"
                disabled={saving}
                onClick={onClose}
                type="button"
              >
                Annuler
              </button>
              <button
                className="primary-action"
                disabled={saving || items.length < 2}
                onClick={() => void save()}
                type="button"
              >
                {saving ? 'Enregistrement…' : 'Enregistrer l’ordre'}
              </button>
            </div>
          </>
        )}
      </div>
    </Modal>
  );
}
