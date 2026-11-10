import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import SeasonPanel from './SeasonPanel';

const t = (key: string) => app.translator.trans(`ernestdefoe-predictions.admin.${key}`, {}, true);
const setting = (key: string) => `ernestdefoe-predictions.${key}`;

/** Groups an Oracle can be put in: not Admin, Guest or Member. */
function groupOptions(): Record<string, string> {
  const options: Record<string, string> = { '': String(t('settings.oracle_group_none')) };
  for (const group of app.store.all<any>('groups')) {
    if (['1', '2', '3'].includes(group.id())) continue;
    options[group.id()] = group.namePlural();
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
        default: '',
        label: t('settings.oracle_group'),
        help: t('settings.oracle_group_help'),
      }),
      70
    )
    .setting(
      () =>
        'fof-badges' in ((globalThis as any).flarum?.extensions || {})
          ? { setting: setting('oracle_badge'), type: 'boolean', label: t('settings.oracle_badge'), help: t('settings.oracle_badge_help') }
          : null,
      60
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
