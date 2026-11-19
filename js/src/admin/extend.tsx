import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import Group from 'flarum/common/models/Group';
import SeasonPanel from './SeasonPanel';

const t = (key: string) => app.translator.trans(`ernestdefoe-predictions.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-predictions.${key}`;

/** Groups an Oracle can be put in: not Admin, Guest, Member or Moderator (Seasons::NEVER_ORACLE_GROUPS). */
function groupOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.oracle_group_none')) };
  // Core has no constant for Moderators' id (4); the server keeps the same list.
  const never = [Group.ADMINISTRATOR_ID, Group.GUEST_ID, Group.MEMBER_ID, '4'];
  for (const group of app.store.all<Group>('groups')) {
    if (never.includes(group.id()!)) continue;
    // "g5", not "5": integer-like keys are sorted first, which would drop "No group" to the bottom.
    options['g' + group.id()] = group.namePlural();
  }
  return options;
}

export default [
  new Extend.Admin()
    .setting(() => ({ setting: setting('nav'), type: 'boolean', label: t('settings.nav') }), 100)
    .setting(() => ({ setting: setting('bold_bonus'), type: 'boolean', label: t('settings.bold_bonus'), help: t('settings.bold_bonus_help') }), 90)
    .setting(
      () => ({
        setting: setting('oracle_count'),
        type: 'number',
        min: 0,
        max: 25,
        label: t('settings.oracle_count'),
        help: t('settings.oracle_count_help'),
      }),
      80
    )
    .setting(
      () => ({
        setting: setting('oracle_group'),
        type: 'select',
        options: groupOptions(),
        label: t('settings.oracle_group'),
        help: t('settings.oracle_group_help'),
      }),
      70
    )
    .setting(
      () =>
        'fof-badges' in flarum.extensions
          ? { setting: setting('oracle_badge'), type: 'boolean', label: t('settings.oracle_badge'), help: t('settings.oracle_badge_help') }
          : null,
      60
    )
    .setting(
      () => ({
        setting: setting('min_pickers'),
        type: 'number',
        min: 1,
        max: 100,
        label: t('settings.min_pickers'),
        help: t('settings.min_pickers_help'),
      }),
      58
    )
    .setting(
      () => ({
        setting: setting('daily_limit'),
        type: 'number',
        min: 1,
        max: 500,
        label: t('settings.daily_limit'),
        help: t('settings.daily_limit_help'),
      }),
      56
    )
    .customSetting(() => <SeasonPanel />, 50)
    .permission(
      () => ({ permission: 'predictions.view', icon: 'fas fa-wand-magic-sparkles', label: t('permissions.view'), allowGuest: true }),
      'view',
      55
    )
    .permission(() => ({ permission: 'predictions.pick', icon: 'fas fa-hand-pointer', label: t('permissions.pick') }), 'reply', 55)
    .permission(() => ({ permission: 'predictions.create', icon: 'fas fa-wand-magic-sparkles', label: t('permissions.create') }), 'start', 55)
    .permission(() => ({ permission: 'predictions.moderate', icon: 'fas fa-flag-checkered', label: t('permissions.moderate') }), 'moderate', 55),
];
