import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { Instrument } from '@cadran/api-client';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './InstrumentTable.module.css';

interface Props {
  items: Instrument[];
  onEdit: (item: Instrument) => void;
  onStatus: (item: Instrument) => void;
}
export function InstrumentTable({ items, onEdit, onStatus }: Props) {
  const { t } = useTranslation();
  const T = 'investments.instruments';
  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('investments.tabs.instruments')}</caption>
          <thead>
            <tr>
              {['name', 'type', 'region', 'status', ''].map((key, index) => (
                <th
                  className={index >= 1 && index <= 3 ? styles.detail : undefined}
                  key={key}
                  scope="col"
                >
                  {key ? t(`${T}.table.${key}` as ParseKeys) : ''}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <th scope="row">
                  <InstrumentLink instrument={item} />
                  <small>
                    {[item.isin, item.ticker].filter(Boolean).join(' · ') ||
                      t(`${T}.table.noIdentifier`)}
                  </small>
                </th>
                <td className={styles.detail}>
                  {t(`${T}.typesShort.${item.instrumentType}`, { defaultValue: '' })}
                  <small className={styles.sub}>
                    {item.assetClass
                      ? t(`${T}.classes.${item.assetClass}`)
                      : t(`${T}.table.classUnset`)}
                  </small>
                </td>
                <td className={styles.detail}>
                  {item.region ? t(`${T}.regions.${item.region}`) : t(`${T}.table.unset`)}
                  <small className={styles.sub}>{item.currencyCode}</small>
                </td>
                <td className={styles.detail}>
                  <StatusBadge tone={item.status === 'ACTIVE' ? 'positive' : 'info'}>
                    {item.status === 'ACTIVE' ? t(`${T}.status.ACTIVE`) : t(`${T}.status.INACTIVE`)}
                  </StatusBadge>
                </td>
                <td>
                  <ActionMenu
                    label={t(`${T}.table.actionsFor`, { name: item.name })}
                    items={[
                      {
                        id: 'edit',
                        icon: 'edit',
                        label: t(`${T}.table.editNamed`, { name: item.name }),
                        text: t(`${T}.actions.edit`),
                        onSelect: () => onEdit(item),
                      },
                      {
                        id: 'status',
                        icon: 'archive',
                        label:
                          item.status === 'ACTIVE'
                            ? t(`${T}.table.archiveNamed`, { name: item.name })
                            : t(`${T}.table.reactivateNamed`, { name: item.name }),
                        text:
                          item.status === 'ACTIVE'
                            ? t(`${T}.actions.archive`)
                            : t(`${T}.actions.reactivate`),
                        onSelect: () => onStatus(item),
                      },
                    ]}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
