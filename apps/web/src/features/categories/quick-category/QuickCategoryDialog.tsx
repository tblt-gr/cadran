import type { Category, CategoryType } from '@cadran/api-client';
import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { QuickCategoryForm } from './QuickCategoryForm';
import { useCreateQuickCategory } from './useCreateQuickCategory';

interface QuickCategoryDialogProps {
  close: () => void;
  initialLabel: string;
  /** Type the draft accepts, prefilled and still editable. */
  initialType: CategoryType;
  onCreated: (category: Category) => void;
  returnFocus: RefObject<HTMLElement | null>;
}

export function QuickCategoryDialog({
  close,
  initialLabel,
  initialType,
  onCreated,
  returnFocus,
}: QuickCategoryDialogProps) {
  const { t } = useTranslation();
  const creation = useCreateQuickCategory();

  // Closing stays possible while a request hangs: the user is never stranded behind two
  // overlays. A request that still succeeds lands in the category caches, but selects
  // nothing, since `mutate` callbacks do not run once the dialog is gone.
  return (
    <Modal
      close={close}
      eyebrow={t('categories.quick.eyebrow')}
      returnFocus={returnFocus}
      title={t('categories.quick.title')}
    >
      <QuickCategoryForm
        initialLabel={initialLabel}
        initialType={initialType}
        onSubmit={(input) =>
          creation.mutate(input, {
            onSuccess: (category) => {
              onCreated(category);
              close();
            },
          })
        }
        pending={creation.isPending}
        submitError={creation.errorKind}
      />
    </Modal>
  );
}
