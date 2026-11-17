import type { AccountOverviewComposition, AccountOverviewGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { groupColor } from '@/features/accounts/group-color';
import { NetWorthCompositionView } from './NetWorthCompositionView';
import { useNetWorthComposition } from './useNetWorthComposition';

/**
 * `Répartition par groupe`: the same card as the account split, one slice per group of the
 * overview. The total and the reason a pie is withheld come from the account split, which covers
 * the same net worth at the same date; nothing is summed here.
 */
export function GroupsCompositionCard({
  asOf,
  groups,
}: {
  asOf: string;
  groups: readonly AccountOverviewGroup[];
}) {
  const { t } = useTranslation();
  const composition = useNetWorthComposition(asOf);
  const data = composition.data;

  const asComposition: AccountOverviewComposition | null = data
    ? {
        ...data,
        accounts: groups.map((group) => ({
          id: group.groupId ?? 'ungrouped',
          label: group.label ?? t('accounts.overview.ungrouped'),
          institution: null,
          primaryGroupId: group.groupId ?? 'ungrouped',
          color: groupColor(group.resolvedColor) ?? group.resolvedColor,
          value: group.total,
          share: group.share,
        })),
      }
    : null;

  if (composition.isError || data?.accounts.length === 0) return null;

  return <NetWorthCompositionView by="group" composition={asComposition} />;
}
