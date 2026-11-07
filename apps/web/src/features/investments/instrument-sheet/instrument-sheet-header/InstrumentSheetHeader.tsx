import type { Instrument } from '@cadran/api-client';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import {
  instrumentTypes,
  instrumentClasses,
  instrumentRegions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
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
      <div className={styles.heading}>
        {instrument?.status === 'INACTIVE' ? <StatusBadge tone="info">Archivé</StatusBadge> : null}
        <p className={styles.identity}>
          {instrument ? parts : pending ? <SkeletonBlock className={styles.identityBlock} /> : null}
        </p>
      </div>
      <HeaderActions>
        <ActionMenu
          disabled={!instrument}
          triggerVariant="framed"
          label={
            instrument ? `Actions de l’instrument ${instrument.name}` : 'Actions de l’instrument'
          }
          items={
            instrument
              ? [
                  {
                    id: 'edit',
                    icon: 'edit',
                    label: 'Modifier',
                    text: 'Modifier',
                    onSelect: onEdit,
                  },
                  {
                    id: 'status',
                    icon: 'archive',
                    label: instrument.status === 'INACTIVE' ? 'Réactiver' : 'Archiver',
                    text: instrument.status === 'INACTIVE' ? 'Réactiver' : 'Archiver',
                    onSelect: onStatus,
                  },
                ]
              : []
          }
        />
      </HeaderActions>
    </header>
  );
}
