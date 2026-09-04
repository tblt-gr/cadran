import type { ProductModel } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import styles from './ProductModelList.module.css';

interface ProductModelListProps {
  models: ProductModel[];
  onInspect: (model: ProductModel) => void;
  onAddPeriod: (model: ProductModel) => void;
  onDuplicate: (model: ProductModel) => void;
  onArchive: (model: ProductModel) => void;
}

export function ProductModelList({
  models,
  onInspect,
  onAddPeriod,
  onDuplicate,
  onArchive,
}: ProductModelListProps) {
  const { t } = useTranslation();

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('productModels.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('productModels.list.name')}</th>
              <th scope="col">{t('productModels.list.family')}</th>
              <th scope="col">{t('productModels.list.yield')}</th>
              <th scope="col">{t('productModels.list.periods')}</th>
              <th scope="col">{t('productModels.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('productModels.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {models.map((model) => (
              <tr key={model.id}>
                <th scope="row">
                  <span>{model.name}</span>
                  <small>
                    {t(`productModels.origins.${model.origin}`, {
                      product: model.basedOnProductCode ?? '',
                    })}
                  </small>
                </th>
                <td>
                  {t(`catalog.accountKinds.${model.family}`)}
                  <small className={styles.sub}>{t(`productModels.natures.${model.nature}`)}</small>
                </td>
                <td>
                  {t(`catalog.yieldKinds.${model.yieldKind}`)}
                  <small className={styles.sub}>
                    {t(
                      model.yieldGuaranteed
                        ? 'productModels.list.guaranteed'
                        : 'productModels.list.revisable',
                    )}
                  </small>
                </td>
                <td>{model.rules.length}</td>
                <td>
                  <StatusBadge tone={model.archivedAt === null ? 'positive' : 'warning'}>
                    {t(
                      model.archivedAt === null
                        ? 'productModels.statuses.ACTIVE'
                        : 'productModels.statuses.ARCHIVED',
                    )}
                  </StatusBadge>
                </td>
                <td className={styles.rowActions}>
                  <button
                    aria-label={t('productModels.list.inspectModel', { name: model.name })}
                    className="secondary-action"
                    onClick={() => onInspect(model)}
                    type="button"
                  >
                    {t('productModels.list.inspect')}
                  </button>
                  <button
                    aria-label={t('productModels.list.addPeriodTo', { name: model.name })}
                    className="secondary-action"
                    disabled={!model.editable}
                    onClick={() => onAddPeriod(model)}
                    type="button"
                  >
                    {t('productModels.list.addPeriod')}
                  </button>
                  <button
                    aria-label={t('productModels.list.duplicateModel', { name: model.name })}
                    className="secondary-action"
                    disabled={!model.editable}
                    onClick={() => onDuplicate(model)}
                    type="button"
                  >
                    {t('productModels.list.duplicate')}
                  </button>
                  <button
                    aria-label={t('productModels.list.archiveModel', { name: model.name })}
                    className="secondary-action"
                    disabled={!model.editable}
                    onClick={() => onArchive(model)}
                    type="button"
                  >
                    {t('productModels.list.archive')}
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
