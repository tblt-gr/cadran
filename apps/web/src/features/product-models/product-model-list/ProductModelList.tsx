import type { ProductModel } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import {
  ProductNameCell,
  ProductPairCell,
  ProductTablePanel,
} from '@/components/ui/product-table/ProductTable';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

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
    <ProductTablePanel
      caption={t('productModels.list.caption')}
      headings={[
        { label: t('productModels.list.name') },
        { label: t('productModels.list.family') },
        { label: t('productModels.list.yield') },
        { label: t('productModels.list.periods') },
        { label: t('productModels.list.status') },
        { label: t('productModels.list.actions'), hidden: true },
      ]}
    >
      <tbody>
        {models.map((model) => (
          <tr key={model.id}>
            <ProductNameCell
              name={model.name}
              note={t(`productModels.origins.${model.origin}`, {
                product: model.basedOnProductCode ?? '',
              })}
            />
            <ProductPairCell
              value={t(`catalog.accountKinds.${model.family}`)}
              note={t(`productModels.natures.${model.nature}`)}
            />
            <ProductPairCell
              value={t(`catalog.yieldKinds.${model.yieldKind}`)}
              note={t(
                model.yieldGuaranteed
                  ? 'productModels.list.guaranteed'
                  : 'productModels.list.revisable',
              )}
            />
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
            <td>
              <ActionMenu
                label={t('productModels.list.openActions', { name: model.name })}
                items={[
                  {
                    icon: 'eye',
                    id: 'inspect',
                    label: t('productModels.list.inspectModel', { name: model.name }),
                    onSelect: () => onInspect(model),
                    text: t('productModels.list.inspect'),
                  },
                  {
                    disabled: !model.editable,
                    icon: 'add',
                    id: 'addPeriod',
                    label: t('productModels.list.addPeriodTo', { name: model.name }),
                    onSelect: () => onAddPeriod(model),
                    text: t('productModels.list.addPeriod'),
                  },
                  {
                    disabled: !model.editable,
                    icon: 'copy',
                    id: 'duplicate',
                    label: t('productModels.list.duplicateModel', { name: model.name }),
                    onSelect: () => onDuplicate(model),
                    text: t('productModels.list.duplicate'),
                  },
                  {
                    disabled: !model.editable,
                    icon: 'archive',
                    id: 'archive',
                    label: t('productModels.list.archiveModel', { name: model.name }),
                    onSelect: () => onArchive(model),
                    text: t('productModels.list.archive'),
                  },
                ]}
              />
            </td>
          </tr>
        ))}
      </tbody>
    </ProductTablePanel>
  );
}
