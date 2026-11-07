import type { Holdings } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import type { HoldingsSort } from '@/features/investments/useHoldings';
import { HoldingRow } from './HoldingRow';
import { SortableHeader } from './SortableHeader';
import styles from './HoldingsTable.module.css';

interface HoldingsTableProps {
  /** Null while the read is pending: headers and caption stay real, only values are blocks. */
  holdings: Holdings | null;
  sort: HoldingsSort;
  onSortChange: (sort: HoldingsSort) => void;
}

const PENDING_ROWS = 4;

/** One line per instrument across every account, ordered by the server. */
export function HoldingsTable({ holdings, sort, onSortChange }: HoldingsTableProps) {
  const { t } = useTranslation();
  const [opened, setOpened] = useState<ReadonlySet<string>>(new Set());
  const toggle = (id: string) =>
    setOpened((current) => {
      const next = new Set(current);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  const header = (label: string, order: HoldingsSort, ascending = false) => (
    <SortableHeader
      appliedDirection={ascending ? 'ascending' : 'descending'}
      direction={sort === order ? (ascending ? 'ascending' : 'descending') : null}
      disabled={!holdings}
      label={label}
      onSort={() => onSortChange(order)}
    />
  );
  const labels = [
    t('investments.table.quantity'),
    t('investments.table.averageCost'),
    t('investments.table.lastPrice'),
    t('investments.table.value'),
    t('investments.table.gain'),
    t('investments.table.share'),
  ];

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('investments.table.caption')}</caption>
          <thead>
            <tr>
              {header(t('investments.table.asset'), 'NAME_ASC', true)}
              <th scope="col">{labels[0]}</th>
              <th scope="col">{labels[1]}</th>
              <th scope="col">{labels[2]}</th>
              {header(t('investments.table.value'), 'VALUE_DESC')}
              {header(t('investments.table.gain'), 'GAIN_DESC')}
              <th scope="col">{labels[5]}</th>
              <th scope="col">
                <span className="sr-only">{t('investments.table.detail')}</span>
              </th>
            </tr>
          </thead>
          <tbody aria-hidden={holdings ? undefined : true}>
            {holdings
              ? holdings.items.map((holding) => (
                  <HoldingRow
                    assetCode={holdings.assetCode}
                    holding={holding}
                    key={holding.instrument.id}
                    onToggle={() => toggle(holding.instrument.id)}
                    open={opened.has(holding.instrument.id)}
                  />
                ))
              : Array.from({ length: PENDING_ROWS }, (_, row) => (
                  <tr key={row}>
                    <th scope="row">
                      <strong className={styles.name}>
                        <SkeletonBlock className={styles.nameBlock} />
                      </strong>
                      <span className={styles.identifier}>
                        <SkeletonBlock className={styles.cellBlock} />
                      </span>
                    </th>
                    {labels.map((label) => (
                      <td className={styles.number} data-label={label} key={label}>
                        <SkeletonBlock className={styles.cellBlock} />
                      </td>
                    ))}
                    <td className={styles.toggle}>
                      <button className={`icon-button ${styles.chevron}`} disabled type="button">
                        <Icon name="chevron-right" size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
