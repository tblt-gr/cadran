import { useId, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import styles from './SheetRow.module.css';

interface SheetProps {
  children: ReactNode;
}

/** The account sheet: one list of label and value lines, split by local 1 px rules. */
export function Sheet({ children }: SheetProps) {
  return <dl className={styles.sheet}>{children}</dl>;
}

interface SheetRowProps {
  /** A short sentence under the value; the line's warning, with its icon. */
  alert?: ReactNode;
  /** The extra reading folded behind the "i" button; absent when the line has none. */
  detail?: ReactNode;
  /** Accessible name of the "i" button, for instance "Détails : Plafond de dépôt". */
  detailLabel?: string;
  /** Takes focus on mount, when the reader comes back from a modal opened from this line. */
  focusAction?: boolean;
  label: ReactNode;
  /** Loading placeholder: the "i" button is kept in place and inert. */
  pending?: boolean;
  /** "Depuis le X" or "Du X au Y", in small muted text under the value. */
  since?: ReactNode;
  value: ReactNode;
  /** Beside the value; only a local claim is flagged, an inherited value is the default. */
  badge?: ReactNode;
}

/**
 * One line of the sheet: label on the left, value in bold tabular figures with
 * its start date under it, the "i" button last. The detail unfolds under the
 * line with no frame of its own.
 */
export function SheetRow({
  alert,
  badge,
  detail,
  detailLabel,
  focusAction = false,
  label,
  pending = false,
  since,
  value,
}: SheetRowProps) {
  const { t } = useTranslation();
  const detailId = useId();
  const [open, setOpen] = useState(false);
  const hasDetail = detail !== undefined && detail !== null;

  return (
    <div className={styles.row}>
      <dt>{label}</dt>
      <dd className={styles.value}>
        <div className={styles.figure}>{value}</div>
        {badge}
        {since ? <small>{since}</small> : null}
        {alert}
      </dd>
      <dd className={styles.info}>
        {hasDetail || pending ? (
          <InfoButton
            aria-controls={pending ? undefined : detailId}
            aria-expanded={pending ? undefined : open}
            data-autofocus={focusAction || undefined}
            disabled={pending}
            label={
              pending ? t('accounts.rules.details') : (detailLabel ?? t('accounts.rules.details'))
            }
            onClick={() => setOpen((current) => !current)}
          />
        ) : null}
      </dd>
      {hasDetail && open ? (
        <dd className={styles.detail} id={detailId}>
          {detail}
        </dd>
      ) : null}
    </div>
  );
}
