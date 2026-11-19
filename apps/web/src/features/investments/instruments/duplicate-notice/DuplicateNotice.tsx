import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { InstrumentCandidate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './DuplicateNotice.module.css';
export function DuplicateNotice({
  candidates,
  editing = false,
  onCancel,
  onConfirm,
  onUse,
  pending = false,
}: {
  candidates: InstrumentCandidate[];
  editing?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
  onUse?: (id: string) => void;
  pending?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <section className={styles.notice} aria-labelledby="duplicate-title">
      <h3 id="duplicate-title">{t('investments.instruments.duplicate.title')}</h3>
      <ul>
        {candidates.map((candidate) => (
          <li key={candidate.id}>
            {candidate.name} · {candidate.isin ?? t('investments.instruments.duplicate.noIsin')} ·{' '}
            {candidate.ticker ?? t('investments.instruments.duplicate.noTicker')} ·{' '}
            {candidate.status === 'ACTIVE'
              ? t('investments.instruments.status.ACTIVE')
              : t('investments.instruments.status.INACTIVE')}
            {onUse ? (
              <button
                aria-label={t('investments.instruments.duplicate.useNamed', {
                  name: candidate.name,
                })}
                className="secondary-action"
                disabled={pending}
                type="button"
                onClick={() => onUse(candidate.id)}
              >
                {candidate.status === 'ACTIVE'
                  ? t('investments.instruments.duplicate.use')
                  : t('investments.instruments.duplicate.reactivateAndUse')}
              </button>
            ) : null}
          </li>
        ))}
      </ul>
      <ModalActions>
        <button className="secondary-action" disabled={pending} onClick={onCancel} type="button">
          {t('actions.cancel')}
        </button>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {editing
            ? t('investments.instruments.duplicate.saveAnyway')
            : t('investments.instruments.duplicate.createAnyway')}
        </button>
      </ModalActions>
    </section>
  );
}
