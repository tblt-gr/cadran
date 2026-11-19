import { useTranslation } from 'react-i18next';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';

/** Overview card of an account that can still take over the positions it already holds. */
export function TakeoverPrompt({ onTakeover }: { onTakeover: () => void }) {
  const { t } = useTranslation();
  return (
    <OperationsState title={t('portfolio.takeover.prompt.title')}>
      <p>{t('portfolio.takeover.prompt.text')}</p>
      <button className="primary-action" onClick={onTakeover} type="button">
        {t('portfolio.takeover.enter')}
      </button>
    </OperationsState>
  );
}
