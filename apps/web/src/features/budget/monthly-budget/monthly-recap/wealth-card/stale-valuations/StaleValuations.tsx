import type { StaleValuation } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { valuedByPortfolio } from '@/features/portfolio/portfolioAccountHref';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './StaleValuations.module.css';

interface StaleValuationsProps {
  /** The quality sentence itself: it is the accessible name of the button. */
  sentence: string;
  /** Month the valuations are read for, as an eyebrow of the modal. */
  period: string | null;
  /** Rows from the API, already sorted oldest first; the count in the sentence equals their number. */
  rows: StaleValuation[];
}

/** The stale-valuation sentence of the wealth card, opening the accounts behind its count. */
export function StaleValuations({ sentence, period, rows }: StaleValuationsProps) {
  const { i18n, t } = useTranslation();
  const [open, setOpen] = useState(false);
  const trigger = useRef<HTMLButtonElement>(null);
  // One sentence per combination, so a missing part never leaves a stray space or parenthesis.
  const detail = (row: StaleValuation): string | null => {
    const date = row.valuedOn ? formatCalendarDay(row.valuedOn, i18n.language) : null;
    const prefix = 'budget.monthly.recap.wealth.staleList';
    if (date !== null && row.ageDays !== null) {
      return t(`${prefix}.dateAndAge`, { date, count: row.ageDays });
    }
    if (date !== null) return t(`${prefix}.dateOnly`, { date });
    if (row.ageDays !== null) return t(`${prefix}.ageOnly`, { count: row.ageDays });
    return null;
  };

  return (
    <>
      <button className={styles.trigger} onClick={() => setOpen(true)} ref={trigger} type="button">
        {sentence}
      </button>
      {open ? (
        <Modal
          close={() => setOpen(false)}
          eyebrow={period ?? undefined}
          returnFocus={trigger}
          title={t('budget.monthly.recap.wealth.staleList.title')}
        >
          {rows.length === 0 ? (
            <p className={styles.empty}>{t('budget.monthly.recap.wealth.staleList.empty')}</p>
          ) : (
            <ul className={styles.list}>
              {rows.map((row) => {
                const href = valuedByPortfolio(row)
                  ? `/portfolios/${row.accountId}`
                  : `/accounts/${row.accountId}`;
                return (
                  <li className={styles.row} key={row.accountId}>
                    <a
                      className={styles.account}
                      href={href}
                      onClick={(event) => handleClientNavigation(event, href)}
                    >
                      {row.label}
                    </a>
                    {row.institution ? (
                      <small className={styles.secondary}>{row.institution}</small>
                    ) : null}
                    {detail(row) ? <small className={styles.secondary}>{detail(row)}</small> : null}
                  </li>
                );
              })}
            </ul>
          )}
        </Modal>
      ) : null}
    </>
  );
}
