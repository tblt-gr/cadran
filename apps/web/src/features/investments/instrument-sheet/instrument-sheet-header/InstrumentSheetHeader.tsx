import type { Instrument } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import {
  instrumentTypeOptions,
  instrumentClassOptions,
  instrumentRegionOptions,
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
  const { t } = useTranslation();
  useHeaderTitle(window.location.pathname, instrument?.name ?? t('investments.sheet.defaultTitle'));
  const parts = instrument
    ? [
        instrument.isin,
        instrument.ticker,
        instrument.venueMic,
        title(instrumentTypeOptions(t), instrument.instrumentType),
        title(instrumentClassOptions(t), instrument.assetClass),
        title(instrumentRegionOptions(t), instrument.region),
        instrument.currencyCode,
      ]
        .filter(Boolean)
        .join(' · ')
    : null;
  return (
    <header className={styles.header}>
      <div className={styles.heading}>
        {instrument?.status === 'INACTIVE' ? (
          <StatusBadge tone="info">{t('investments.instruments.status.INACTIVE')}</StatusBadge>
        ) : null}
        <p className={styles.identity}>
          {instrument ? parts : pending ? <SkeletonBlock className={styles.identityBlock} /> : null}
        </p>
      </div>
      <HeaderActions>
        <ActionMenu
          disabled={!instrument}
          triggerVariant="framed"
          label={
            instrument
              ? t('investments.sheet.actionsLabelNamed', { name: instrument.name })
              : t('investments.sheet.actionsLabel')
          }
          items={
            instrument
              ? [
                  {
                    id: 'edit',
                    icon: 'edit',
                    label: t('investments.instruments.actions.edit'),
                    text: t('investments.instruments.actions.edit'),
                    onSelect: onEdit,
                  },
                  {
                    id: 'status',
                    icon: 'archive',
                    label:
                      instrument.status === 'INACTIVE'
                        ? t('investments.instruments.actions.reactivate')
                        : t('investments.instruments.actions.archive'),
                    text:
                      instrument.status === 'INACTIVE'
                        ? t('investments.instruments.actions.reactivate')
                        : t('investments.instruments.actions.archive'),
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
