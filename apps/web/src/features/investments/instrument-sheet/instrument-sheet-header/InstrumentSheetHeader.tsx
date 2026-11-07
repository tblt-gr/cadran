import type { Instrument } from '@cadran/api-client';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import {
  instrumentTypes,
  instrumentClasses,
  instrumentRegions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './InstrumentSheetHeader.module.css';

const title = (options: readonly (readonly [string, string])[], value: string | null) =>
  options.find(([key]) => key === value)?.[1] ?? value;
export function InstrumentSheetHeader({
  instrument,
  pending,
  onEdit,
  onStatus,
}: {
  instrument: Instrument | null;
  pending: boolean;
  onEdit: () => void;
  onStatus: () => void;
}) {
  useHeaderTitle(window.location.pathname, instrument?.name ?? 'Instrument');
  const parts = instrument
    ? [
        instrument.isin,
        instrument.ticker,
        instrument.venueMic,
        title(instrumentTypes, instrument.instrumentType),
        title(instrumentClasses, instrument.assetClass),
        title(instrumentRegions, instrument.region),
        instrument.currencyCode,
      ]
        .filter(Boolean)
        .join(' · ')
    : null;
  return (
    <header className={styles.header}>
      <a
        href="/investments/instruments"
        onClick={(event) => handleClientNavigation(event, '/investments/instruments')}
      >
        Voir tous les instruments
      </a>
      <div className={styles.heading}>
        {instrument?.status === 'INACTIVE' ? <StatusBadge tone="info">Archivé</StatusBadge> : null}
      </div>
      <p className={styles.identity}>
        {instrument ? parts : pending ? <SkeletonBlock className={styles.identityBlock} /> : null}
      </p>
      <div className={styles.actions}>
        <button className="secondary-action" type="button" disabled={!instrument} onClick={onEdit}>
          Modifier
        </button>
        <button
          className="secondary-action"
          type="button"
          disabled={!instrument}
          onClick={onStatus}
        >
          {instrument?.status === 'INACTIVE' ? 'Réactiver' : 'Archiver'}
        </button>
      </div>
    </header>
  );
}
