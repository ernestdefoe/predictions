import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import Avatar from 'flarum/common/components/Avatar';
import Tooltip from 'flarum/common/components/Tooltip';
import stringToColor from 'flarum/common/utils/stringToColor';
import type Mithril from 'mithril';
import SettleModal from './SettleModal';
import { openForm, optionLabel, percent, predictionUrl, t, tx, type Option, type Person, type Prediction } from '../api';
import { countdown, num, pick, remove, save, when } from '../data';

declare const m: Mithril.Static;
declare const flarum: any;

export interface PredictionCardAttrs extends ComponentAttrs {
  prediction: Prediction;
  /** Where it's shown: a full card on the page, a slimmer one inside a post. */
  context?: 'page' | 'post' | 'discussion' | 'single';
  ondelete?: () => void;
}

const ICONS = { choice: 'fas fa-list-check', yesno: 'fas fa-circle-half-stroke', number: 'fas fa-bullseye' };

/*
 * Countdowns tick once a minute while any card is on screen; nothing runs
 * when none is.
 */
let mounted = 0;
let ticker = 0;
function tick(on: boolean) {
  mounted += on ? 1 : -1;
  if (mounted > 0 && !ticker) ticker = window.setInterval(() => m.redraw(), 30000);
  if (mounted <= 0 && ticker) {
    window.clearInterval(ticker);
    ticker = 0;
  }
}

/** A user as a link to their profile, with the avatar. Names are text, never HTML. */
export class PersonLink extends Component<{ person: Person; avatar?: boolean }> {
  view() {
    const p = this.attrs.person;
    return (
      <Link className="PredictionPerson" href={app.route('user', { username: p.slug })}>
        {this.attrs.avatar !== false && <Avatar user={fakeUser(p)} className="PredictionAvatar" />}
        <span className="PredictionPerson-name">{p.displayName}</span>
      </Link>
    );
  }
}

/** Avatar takes a User model; ours is plain JSON, so it gets the few methods Avatar calls. */
export function fakeUser(p: Person): any {
  return {
    displayName: () => p.displayName,
    username: () => p.username,
    avatarUrl: () => p.avatarUrl,
    color: () => '#' + stringToColor(p.username),
    id: () => String(p.id),
  };
}

export default class PredictionCard extends Component<PredictionCardAttrs> {
  busy = false;
  guess = '';

  oncreate(vnode: Mithril.VnodeDOM<PredictionCardAttrs, this>) {
    super.oncreate(vnode);
    tick(true);
  }

  onremove(vnode: Mithril.VnodeDOM<PredictionCardAttrs, this>) {
    super.onremove(vnode);
    tick(false);
  }

  view() {
    const p = this.attrs.prediction;
    const context = this.attrs.context || 'page';

    return (
      <div
        className={`PredictionCard PredictionCard--${p.status} PredictionCard--${p.type} PredictionCard--in-${context}`}
        data-prediction={p.id}
        aria-busy={this.busy ? 'true' : undefined}
      >
        <div className="PredictionCard-head">
          <span className="PredictionCard-kind">
            <Icon name={ICONS[p.type]} /> {t(`card.kind.${p.type}`)}
          </span>
          {this.statusChip(p)}
          {this.controls(p)}
        </div>

        {context === 'single' ? (
          <h2 className="PredictionCard-title">{p.title}</h2>
        ) : (
          <h3 className="PredictionCard-title">
            <Link href={app.route('predictions.show', { id: p.id })}>{p.title}</Link>
          </h3>
        )}
        {p.description && <p className="PredictionCard-description">{p.description}</p>}

        {p.type === 'number' ? this.numberBody(p) : this.optionsBody(p)}

        {this.result(p)}
        {this.footer(p, context)}
      </div>
    );
  }

  statusChip(p: Prediction) {
    const label =
      p.status === 'open'
        ? t('card.status.open', { time: countdown(p.lockAt) })
        : p.status === 'locked'
          ? t('card.status.locked')
          : p.status === 'void'
            ? t('card.status.void')
            : t('card.status.settled');

    return (
      <Tooltip text={p.status === 'open' ? tx('card.locks_at', { time: when(p.lockAt) }) : when(p.settledAt || p.lockAt)}>
        <span className={`PredictionCard-status PredictionCard-status--${p.status}`}>
          <span className="PredictionCard-dot" aria-hidden="true" />
          {label}
        </span>
      </Tooltip>
    );
  }

  controls(p: Prediction) {
    const items: Mithril.Children[] = [];
    if (p.can.settle && p.status !== 'settled' && p.status !== 'void')
      items.push(
        <Button icon="fas fa-flag-checkered" onclick={() => this.openSettle()}>
          {t('card.settle')}
        </Button>
      );
    if ((p.can.settle || p.can.void) && (p.status === 'settled' || p.status === 'void'))
      items.push(
        <Button icon="fas fa-rotate" onclick={() => this.openSettle()}>
          {t('card.correct')}
        </Button>
      );
    if (p.can.edit)
      items.push(
        <Button icon="fas fa-pencil" onclick={() => openForm({ prediction: p })}>
          {t('card.edit')}
        </Button>
      );
    if (p.can.edit && p.status === 'open')
      items.push(
        <Button icon="fas fa-lock" onclick={() => this.lockNow()}>
          {t('card.lock_now')}
        </Button>
      );
    items.push(
      <Button icon="fas fa-link" onclick={() => this.copyLink()}>
        {t('card.copy_link')}
      </Button>
    );
    if (p.can.delete)
      items.push(
        <Button icon="fas fa-trash-can" onclick={() => this.remove()}>
          {t('card.delete')}
        </Button>
      );

    return (
      <Dropdown
        className="PredictionCard-controls"
        buttonClassName="Button Button--icon Button--flat"
        menuClassName="Dropdown-menu--right"
        icon="fas fa-ellipsis"
        label={tx('card.controls')}
        accessibleToggleLabel={tx('card.controls')}
      >
        {items}
      </Dropdown>
    );
  }

  optionsBody(p: Prediction) {
    const total = p.options.reduce((sum, o) => sum + (o.count || 0), 0);
    const mine = p.myPick?.optionId ?? null;
    const done = p.status === 'settled' || p.status === 'void';

    return (
      <ul className={'PredictionOptions' + (p.type === 'yesno' ? ' PredictionOptions--yesno' : '')} role="list">
        {p.options.map((option, i) => {
          const share = p.picksVisible && total ? (option.count || 0) / total : null;
          const isMine = option.id === mine;
          const classes = [
            'PredictionOption',
            isMine && 'is-mine',
            option.isCorrect && 'is-correct',
            done && isMine && option.isCorrect === false && 'is-wrong',
            p.can.pick && 'is-pickable',
          ]
            .filter(Boolean)
            .join(' ');

          return (
            <li>
              <button
                type="button"
                className={classes}
                aria-pressed={isMine ? 'true' : 'false'}
                disabled={this.busy || (!p.can.pick && !(p.status === 'open' && !app.session.user))}
                onclick={() => this.choose(option)}
              >
                {share !== null && <span className="PredictionOption-bar" style={{ width: `${Math.round(share * 100)}%` }} aria-hidden="true" />}
                <span className="PredictionOption-label">
                  {option.isCorrect ? <Icon name="fas fa-circle-check" /> : isMine ? <Icon name="fas fa-circle-dot" /> : null}
                  {optionLabel(p, option, i)}
                </span>
                {share !== null && (
                  <span className="PredictionOption-share">
                    {percent(share)}
                    <span className="PredictionOption-count">{tx('card.picks_short', { count: option.count || 0 })}</span>
                  </span>
                )}
              </button>
            </li>
          );
        })}
      </ul>
    );
  }

  numberBody(p: Prediction) {
    const mine = p.myPick?.value ?? null;
    const unit = p.unit ? ` ${p.unit}` : '';

    return (
      <div className="PredictionNumber">
        {p.can.pick ? (
          <form className="PredictionNumber-form" onsubmit={(e: SubmitEvent) => this.submitGuess(e)}>
            <label className="PredictionNumber-field">
              <span className="sr-only">{t('card.your_guess')}</span>
              <input
                className="FormControl"
                type="number"
                step="any"
                inputmode="decimal"
                placeholder={mine !== null ? num(mine) : tx('card.guess_placeholder')}
                value={this.guess}
                oninput={(e: InputEvent) => (this.guess = (e.target as HTMLInputElement).value)}
              />
              {p.unit && <span className="PredictionNumber-unit">{p.unit}</span>}
            </label>
            <Button type="submit" className="Button Button--primary" loading={this.busy} disabled={this.guess.trim() === ''}>
              {mine !== null ? t('card.change_guess') : t('card.lock_in')}
            </Button>
          </form>
        ) : (
          !app.session.user &&
          p.status === 'open' && (
            <Button className="Button Button--primary PredictionCard-login" onclick={() => this.login()}>
              {t('card.log_in_to_pick')}
            </Button>
          )
        )}

        {mine !== null && (
          <p className="PredictionNumber-mine">
            {t('card.your_guess_is', { value: num(mine) + unit })}
            {p.answer !== null && (
              <span className="PredictionNumber-off"> · {t('card.off_by', { value: num(Math.abs(mine - p.answer)) + unit })}</span>
            )}
          </p>
        )}

        {p.answer !== null && (
          <p className="PredictionNumber-answer">
            {t('card.answer_was')} <strong>{num(p.answer) + unit}</strong>
          </p>
        )}

        {p.stats && this.histogram(p)}

        {p.stats?.closest?.length ? (
          <ol className="PredictionClosest" aria-label={tx('card.closest')}>
            {p.stats.closest.map((row) => (
              <li className={row.points >= 20 ? 'is-best' : ''}>
                <PersonLink person={row.user} />
                <span className="PredictionClosest-value">{num(row.value) + unit}</span>
                {row.points > 0 && <span className="PredictionPoints">+{row.points}</span>}
              </li>
            ))}
          </ol>
        ) : null}
      </div>
    );
  }

  /** Where the guesses fell, with the answer and the viewer's own guess marked. */
  histogram(p: Prediction) {
    const s = p.stats!;
    const peak = Math.max(1, ...s.bins);
    const span = s.max - s.min;
    const at = (v: number | null) => (v === null || span <= 0 ? null : Math.min(100, Math.max(0, ((v - s.min) / span) * 100)));
    const answer = at(p.answer);
    const mine = at(p.myPick?.value ?? null);
    const unit = p.unit ? ` ${p.unit}` : '';

    return (
      <figure className="PredictionHistogram">
        <div
          className="PredictionHistogram-bars"
          role="img"
          aria-label={tx('card.histogram_label', { count: s.count, min: num(s.min), max: num(s.max) })}
        >
          {s.bins.map((n) => (
            <span className="PredictionHistogram-bar" style={{ height: `${Math.max(n ? 8 : 0, (n / peak) * 100)}%` }} />
          ))}
          {mine !== null && (
            <span className="PredictionHistogram-mark PredictionHistogram-mark--mine" style={{ left: `${mine}%` }} title={tx('card.you')} />
          )}
          {answer !== null && (
            <span className="PredictionHistogram-mark PredictionHistogram-mark--answer" style={{ left: `${answer}%` }} title={tx('card.answer')} />
          )}
        </div>
        <figcaption className="PredictionHistogram-caption">
          <span>{num(s.min) + unit}</span>
          <span>{t('card.stats', { count: s.count, avg: num(s.avg), median: num(s.median) })}</span>
          <span>{num(s.max) + unit}</span>
        </figcaption>
      </figure>
    );
  }

  result(p: Prediction) {
    if (p.status === 'void') return <p className="PredictionResult PredictionResult--void">{t('card.voided')}</p>;
    // Too few pickers: it can be played, but it won't move the leaderboard. Said up front, not after.
    const uncounted =
      p.counted === false ? (
        <p className="PredictionCard-uncounted">{t('card.not_counted', { count: p.minPickers })}</p>
      ) : p.status !== 'settled' && p.picksCount < p.minPickers ? (
        <p className="PredictionCard-uncounted">{t('card.needs_pickers', { count: p.minPickers })}</p>
      ) : null;
    if (p.status !== 'settled' || !p.myPick) return uncounted;
    if (!p.scored) return <p className="PredictionResult PredictionResult--pending">{t('card.scoring')}</p>;
    if (p.myPick.hit === null) return <p className="PredictionResult PredictionResult--void">{t('card.settler')}</p>;

    return [
      p.myPick.hit ? (
        <p className="PredictionResult PredictionResult--hit">
          <Icon name="fas fa-bullseye" /> {t('card.called_it')}
          {p.myPick.points !== null && <span className="PredictionPoints">+{p.myPick.points}</span>}
        </p>
      ) : (
        <p className="PredictionResult PredictionResult--miss">{t('card.missed')}</p>
      ),
      uncounted,
    ];
  }

  footer(p: Prediction, context: string) {
    return (
      <div className="PredictionCard-foot">
        <span className="PredictionCard-count">
          <Icon name="fas fa-users" /> {t('card.picks', { count: p.picksCount })}
        </span>
        {p.hidePicks && p.status === 'open' && (
          <span className="PredictionCard-hidden">
            <Icon name="fas fa-eye-slash" /> {t('card.hidden_until_lock')}
          </span>
        )}
        {p.user && context !== 'post' && (
          <span className="PredictionCard-by">
            {t('card.by')} <PersonLink person={p.user} avatar={false} />
          </span>
        )}
        {p.discussion && context !== 'discussion' && context !== 'post' && (
          <Link
            className="PredictionCard-discussion"
            href={app.route('discussion', { id: p.discussion.id + (p.discussion.slug ? '-' + p.discussion.slug : '') })}
          >
            <Icon name="far fa-comments" /> {p.discussion.title}
          </Link>
        )}
        {p.myPick && p.status === 'open' && (
          <Button className="Button Button--link PredictionCard-withdraw" onclick={() => this.withdraw()} disabled={this.busy}>
            {t('card.withdraw')}
          </Button>
        )}
      </div>
    );
  }

  choose(option: Option) {
    const p = this.attrs.prediction;
    if (!app.session.user) return this.login();
    if (!p.can.pick || this.busy || p.myPick?.optionId === option.id) return;
    this.run(pick(p.id, { optionId: option.id }));
  }

  submitGuess(e: SubmitEvent) {
    e.preventDefault();
    const value = parseFloat(this.guess.replace(',', '.'));
    if (!isFinite(value)) return;
    this.run(pick(this.attrs.prediction.id, { value }).then((p) => ((this.guess = ''), p)));
  }

  withdraw() {
    this.run(pick(this.attrs.prediction.id, null));
  }

  lockNow() {
    if (!confirm(tx('card.lock_now_confirm'))) return;
    this.run(save(this.attrs.prediction.id, { lockNow: true }));
  }

  remove() {
    if (!confirm(tx('card.delete_confirm'))) return;
    this.busy = true;
    remove(this.attrs.prediction.id)
      .then(() => {
        app.alerts.show({ type: 'success' }, tx('card.deleted'));
        this.attrs.ondelete?.();
      })
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }

  copyLink() {
    const url = predictionUrl(this.attrs.prediction.id);
    const done = () => app.alerts.show({ type: 'success' }, tx('card.link_copied'));
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(done, () => prompt(tx('card.copy_link'), url));
    else prompt(tx('card.copy_link'), url);
  }

  openSettle() {
    app.modal.show(SettleModal, { prediction: this.attrs.prediction });
  }

  login() {
    app.modal.show(() => flarum.reg.asyncModuleImport('flarum/forum/components/LogInModal'));
  }

  run(promise: Promise<unknown>) {
    this.busy = true;
    promise
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
