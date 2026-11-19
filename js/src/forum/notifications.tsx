import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import type PredictionModel from './models/PredictionModel';
import { t } from './api';

/** "You called it!", "A prediction you picked was settled" and "time to settle": each opens the prediction. */
export class CalledNotification extends Notification {
  icon() {
    return 'fas fa-bullseye';
  }

  href() {
    return app.route('predictions.show', { id: this.prediction()?.id() });
  }

  content() {
    return t('notification.called');
  }

  excerpt() {
    return this.prediction()?.title() ?? '';
  }

  prediction(): PredictionModel | undefined {
    return (this.attrs.notification.subject() as PredictionModel | false | undefined) || undefined;
  }
}

export class MissedNotification extends CalledNotification {
  icon() {
    return 'fas fa-flag-checkered';
  }

  content() {
    return t('notification.missed');
  }
}

export class LockedNotification extends CalledNotification {
  icon() {
    return 'fas fa-lock';
  }

  content() {
    return t('notification.locked');
  }
}
