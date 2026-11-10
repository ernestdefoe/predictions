import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import type ItemList from 'flarum/common/utils/ItemList';
import LinkButton from 'flarum/common/components/LinkButton';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Notification from 'flarum/forum/components/Notification';
import CommentPost from 'flarum/forum/components/CommentPost';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import type Mithril from 'mithril';
import scan from './embed';
import { openForm, percent, predictionUrl, t } from './api';

export { default as extend } from './extend';

/*
 * forum.js is loaded on every page, so it only wires things up: the routes,
 * the sidebar link, the notifications, the composer button, the discussion
 * menu item, the profile line, and the scan that turns a prediction link
 * into its card. The card, the pages and the forms are one chunk fetched
 * when first needed.
 */

declare const m: Mithril.Static;

function subjectTitle(notification: any): string {
  return notification.subject()?.attribute?.('title') ?? '';
}

class CalledNotification extends Notification {
  icon() {
    return 'fas fa-bullseye';
  }
  href() {
    return app.route('predictions.show', { id: (this.attrs.notification as any).subject()?.id() });
  }
  content() {
    return t('notification.called');
  }
  excerpt() {
    return subjectTitle(this.attrs.notification);
  }
}

class MissedNotification extends CalledNotification {
  icon() {
    return 'fas fa-flag-checkered';
  }
  content() {
    return t('notification.missed');
  }
}

class LockedNotification extends CalledNotification {
  icon() {
    return 'fas fa-lock';
  }
  content() {
    return t('notification.locked');
  }
}

/**
 * The discussion's own predictions under its first post, less any that post
 * already embeds. Mounted again only when the list changes.
 */
function attach(host: HTMLElement, discussion: any, embedded: number[]) {
  const ids: number[] = (discussion?.attribute('predictionIds') || []).filter((id: number) => !embedded.includes(id));
  const key = ids.join(',');
  if (host.dataset.ids === key) return;
  host.dataset.ids = key;
  if (ids.length || key !== '') import('./components').then(({ mountCards }) => mountCards(host, ids, 'discussion'));
}

const lazy = (page: 'PredictionsPage' | 'PredictionPage') => () =>
  import('./components').then(async (ui) => (await ui.loadCore(), { default: ui[page] }));

/** The composer the button is in: add the link on its own line, where it becomes the card. */
function composerButton(this: any, items: ItemList<Mithril.Children>, priority: number) {
  if (!app.forum.attribute('predictionsCanCreate')) return;
  const composer = this.composer || this.attrs.composer;
  items.add(
    'prediction',
    <Button
      className="PredictionComposerButton Button Button--ua-reset"
      onclick={() =>
        openForm({
          onsave: (p) => composer.editor?.insertAtCursor((composer.fields.content() ? '\n\n' : '') + predictionUrl(p.id) + '\n\n'),
        })
      }
    >
      <span className="PredictionComposerButton-label">
        <Icon name="fas fa-wand-magic-sparkles" /> {t('composer.add')}
      </span>
    </Button>,
    priority
  );
}

app.initializers.add('ernestdefoe/predictions', () => {
  app.routes['predictions'] = { path: '/predictions', component: lazy('PredictionsPage') };
  app.routes['predictions.show'] = { path: '/predictions/:id', component: lazy('PredictionPage') };

  app.notificationComponents.predictionCalled = CalledNotification;
  app.notificationComponents.predictionMissed = MissedNotification;
  app.notificationComponents.predictionLocked = LockedNotification;

  // String form: these components live in lazy chunks, and extending an imported copy patches one core never renders.
  extend('flarum/forum/components/IndexSidebar', 'navItems', function (items: ItemList<any>) {
    if (!app.forum.attribute('predictionsCanView') || !app.forum.attribute('predictionsNav')) return;
    items.add('predictions', LinkButton.component({ href: app.route('predictions'), icon: 'fas fa-wand-magic-sparkles' }, t('nav')), 75);
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: ItemList<any>) {
    if (!app.forum.attribute('predictionsCanView')) return;
    items.add('predictionCalled', { name: 'predictionCalled', icon: 'fas fa-bullseye', label: t('notification.preference_called') });
    items.add('predictionMissed', { name: 'predictionMissed', icon: 'fas fa-flag-checkered', label: t('notification.preference_missed') });
    items.add('predictionLocked', { name: 'predictionLocked', icon: 'fas fa-lock', label: t('notification.preference_locked') });
  });

  // A prediction link on its own line shows as its card. onupdate too: an edited post replaces its content.
  // Under the first post: the discussion's own predictions, less any its first post already shows.
  extend(CommentPost.prototype, ['oncreate', 'onupdate'], function (this: CommentPost) {
    const embedded = scan(this.element);
    const post: any = this.attrs.post;
    const host = this.element.querySelector<HTMLElement>('.PredictionAttached');
    if (host) attach(host, post.discussion(), embedded);
  });

  extend(CommentPost.prototype, 'content', function (this: CommentPost, items: Mithril.Children[]) {
    const post: any = this.attrs.post;
    if (post.number() === 1 && !post.isHidden() && app.forum.attribute('predictionsCanView')) {
      items.push(<div className="PredictionAttached" />);
    }
  });

  // "Add a prediction" for the discussion's author (or a moderator).
  extend(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: any) {
    const user = app.session.user;
    if (!user || !app.forum.attribute('predictionsCanCreate') || !discussion.canReply?.()) return;
    if (discussion.user() !== user && !app.forum.attribute('predictionsCanModerate')) return;
    items.add(
      'prediction',
      <Button
        icon="fas fa-wand-magic-sparkles"
        onclick={() =>
          openForm({
            discussionId: Number(discussion.id()),
            onsave: (p) => {
              discussion.pushAttributes({ predictionIds: [...(discussion.attribute('predictionIds') || []), p.id] });
              const host = document.querySelector<HTMLElement>('.PostStream-item[data-number="1"] .PredictionAttached');
              if (host) attach(host, discussion, []);
              m.redraw();
            },
          })
        }
      >
        {t('discussion.add')}
      </Button>,
      -5
    );
  });

  // In the composer header, after the title (phones lift the first item into the top bar).
  extend('flarum/forum/components/DiscussionComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    composerButton.call(this, items, 1);
  });
  extend('flarum/forum/components/ReplyComposer', 'headerItems', function (this: any, items: ItemList<Mithril.Children>) {
    composerButton.call(this, items, -5);
  });

  // The profile's record: "18 of 25 called (72%) · Oracle ×2".
  extend('flarum/forum/components/UserCard', 'infoItems', function (this: any, items: ItemList<any>) {
    const record = this.attrs.user.attribute('predictionRecord');
    if (!record) return;
    items.add(
      'predictions',
      <Link className="UserCard-predictions" href={app.route('predictions') + '?tab=leaderboard'}>
        <Icon name="fas fa-wand-magic-sparkles" />{' '}
        {t('profile.record', { hits: record.hits, settled: record.settled, rate: percent(record.hits / record.settled) })}
        {record.oracle ? <span className="UserCard-oracle">{t('profile.oracle', { count: record.oracle })}</span> : null}
      </Link>,
      84
    );
  });
});
