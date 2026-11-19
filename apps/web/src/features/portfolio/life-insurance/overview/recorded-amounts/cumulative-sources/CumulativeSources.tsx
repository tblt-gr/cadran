import { useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { capturePortfolioSource } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';

export function CumulativeSources({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const cumulative = summary?.cumulative;

  return (
    <Disclosure
      title={t('portfolio.lifeInsurance.recorded.sources')}
      open={open}
      onToggle={setOpen}
    >
      {cumulative && summary ? (
        <>
          {cumulative.sources.length ? (
            <ul>
              {cumulative.sources.map((source) => {
                const href = sourceHref(accountId, summary.asOf, source.kind, source.id);
                return (
                  <li key={`${source.kind}-${source.id}`}>
                    <a
                      href={href}
                      onClick={(event) => {
                        capturePortfolioSource(event.currentTarget, sourceReturnFocus);
                        handleClientNavigation(event, href);
                      }}
                    >
                      {t(`portfolio.explanation.source.${source.kind}`, {
                        date: formatCalendarNumericDay(source.date),
                      })}
                    </a>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p>{t('portfolio.explanation.noSource')}</p>
          )}
          {cumulative.sourceCount > cumulative.sources.length ? (
            <p>
              {t('portfolio.lifeInsurance.recorded.moreSources', {
                count: cumulative.sourceCount - cumulative.sources.length,
              })}
            </p>
          ) : null}
        </>
      ) : null}
    </Disclosure>
  );
}
