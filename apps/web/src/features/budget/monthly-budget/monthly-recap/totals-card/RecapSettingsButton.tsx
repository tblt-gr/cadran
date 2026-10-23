import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';

export function RecapSettingsButton({
  disabled = false,
  onClick,
}: {
  disabled?: boolean;
  onClick?: () => void;
}) {
  const { t } = useTranslation();
  const label = t('budget.monthly.recap.preferences.open');
  return (
    <button
      aria-label={label}
      className="icon-button"
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      <Icon name="settings" size={18} />
    </button>
  );
}
