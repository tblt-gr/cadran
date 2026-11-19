import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { OperationsState } from './OperationsState';

export function NotPortfolioState({ accountId }: { accountId: string }) {
  const { t } = useTranslation();
  const href = `/accounts/${accountId}`;
  return (
    <OperationsState title={t('portfolio.account.notPortfolio.title')}>
      <a
        className="secondary-action"
        href={href}
        onClick={(event) => handleClientNavigation(event, href)}
      >
        {t('portfolio.account.notPortfolio.open')}
      </a>
    </OperationsState>
  );
}
