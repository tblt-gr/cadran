import type { AccountOverviewGroup } from '@cadran/api-client';
import type { TFunction } from 'i18next';

/** The section name: the group label, or the wording of the ungrouped section. */
export function accountGroupName(group: AccountOverviewGroup, sole: boolean, t: TFunction): string {
  const name =
    group.groupId === null
      ? t(sole ? 'accounts.overview.sole' : 'accounts.overview.ungrouped')
      : (group.label ?? '');

  return group.parentLabel === null ? name : `${name} (${group.parentLabel})`;
}
