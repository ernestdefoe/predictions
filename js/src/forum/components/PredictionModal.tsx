import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { t, tx, type Prediction, type PredictionType } from '../api';
import { save } from '../data';

/**
 * Open (or edit) a prediction: the question, its kind, the choices, when picks
 * lock and whether they stay hidden until then. Attached to a discussion when
 * opened from one.
 */

declare const m: Mithril.Static;

interface Attrs extends IFormModalAttrs {
  prediction?: Prediction;
  discussionId?: number;
  onsave?: (p: Prediction) => void;
}

/** A Date as the value of a datetime-local input, in the reader's own time zone. */
function local(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

const TYPES: Array<[PredictionType, string]> = [
  ['choice', 'fas fa-list-check'],
  ['yesno', 'fas fa-circle-half-stroke'],
  ['number', 'fas fa-bullseye'],
];

export default class PredictionModal extends FormModal<Attrs> {
  type: PredictionType = 'choice';
  question = '';
  description = '';
  options: string[] = ['', ''];
  unit = '';
  tolerance = '';
  lockAt = local(new Date(Date.now() + 86400000));
  hidePicks = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const p = this.attrs.prediction;
    if (p) {
      this.type = p.type;
      this.question = p.title;
      this.description = p.description || '';
      this.options = p.type === 'choice' ? p.options.map((o) => o.label) : ['', ''];
      this.unit = p.unit || '';
      this.tolerance = p.tolerance ? String(p.tolerance) : '';
      this.lockAt = local(new Date(p.lockAt));
      this.hidePicks = p.hidePicks;
    }
  }

  className() {
    return 'PredictionModal Modal--medium';
  }

  title() {
    return this.attrs.prediction ? tx('form.edit_title') : tx('form.title');
  }

  content() {
    const editing = this.attrs.prediction;
    const optionsLocked = !!editing && !editing.can.editOptions;
    const lockLocked = !!editing && editing.status !== 'open';
    // Once others have picked, only a moderator changes the question or whether picks are hidden.
    const wordingLocked = !!editing && !editing.can.editWording;

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label for="prediction-title">{t('form.question')}</label>
            <input
              id="prediction-title"
              className="FormControl"
              maxlength={150}
              required
              disabled={wordingLocked}
              placeholder={tx('form.question_placeholder')}
              value={this.question}
              oninput={(e: InputEvent) => (this.question = (e.target as HTMLInputElement).value)}
            />
          </div>

          <div className="Form-group">
            <label for="prediction-description">{t('form.details')}</label>
            <textarea
              id="prediction-description"
              className="FormControl"
              rows={2}
              maxlength={1000}
              placeholder={tx('form.details_placeholder')}
              value={this.description}
              oninput={(e: InputEvent) => (this.description = (e.target as HTMLTextAreaElement).value)}
            />
          </div>

          <fieldset className="Form-group PredictionModal-types" disabled={optionsLocked}>
            <legend>{t('form.kind')}</legend>
            <div className="PredictionModal-typeRow" role="radiogroup">
              {TYPES.map(([type, icon]) => (
                <label className={'PredictionModal-type' + (this.type === type ? ' is-active' : '')}>
                  <input type="radio" name="prediction-type" value={type} checked={this.type === type} onchange={() => (this.type = type)} />
                  <Icon name={icon} />
                  <span className="PredictionModal-typeName">{t(`card.kind.${type}`)}</span>
                  <span className="PredictionModal-typeHelp">{t(`form.kind_help.${type}`)}</span>
                </label>
              ))}
            </div>
            {optionsLocked && <p className="helpText">{t('form.options_locked')}</p>}
          </fieldset>

          {this.type === 'choice' && this.optionFields(optionsLocked)}
          {this.type === 'number' && this.numberFields(optionsLocked)}

          <div className="Form-group">
            <label for="prediction-lock">{t('form.lock_at')}</label>
            <input
              id="prediction-lock"
              className="FormControl"
              type="datetime-local"
              required
              disabled={lockLocked}
              min={local(new Date(Date.now() + 120000))}
              value={this.lockAt}
              oninput={(e: InputEvent) => (this.lockAt = (e.target as HTMLInputElement).value)}
            />
            <p className="helpText">{t('form.lock_help')}</p>
          </div>

          <div className="Form-group">
            <Switch state={this.hidePicks} disabled={lockLocked || wordingLocked} onchange={(value: boolean) => (this.hidePicks = value)}>
              {t('form.hide_picks')}
            </Switch>
            <p className="helpText">{t(wordingLocked ? 'form.wording_locked' : 'form.hide_picks_help')}</p>
          </div>

          <div className="Form-group">
            <Button className="Button Button--primary Button--block" type="submit" loading={this.loading}>
              {editing ? t('form.save') : t('form.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  optionFields(locked: boolean) {
    return (
      <fieldset className="Form-group PredictionModal-options" disabled={locked}>
        <legend>{t('form.choices')}</legend>
        {this.options.map((value, i) => (
          <div className="PredictionModal-option">
            <input
              className="FormControl"
              maxlength={80}
              aria-label={tx('form.choice_label', { number: i + 1 })}
              placeholder={tx('form.choice_label', { number: i + 1 })}
              value={value}
              oninput={(e: InputEvent) => (this.options[i] = (e.target as HTMLInputElement).value)}
            />
            {this.options.length > 2 && (
              <Button
                className="Button Button--icon Button--flat"
                icon="fas fa-xmark"
                aria-label={tx('form.remove_choice')}
                onclick={() => this.options.splice(i, 1)}
              />
            )}
          </div>
        ))}
        {this.options.length < 12 && (
          <Button className="Button Button--link" icon="fas fa-plus" onclick={() => this.options.push('')}>
            {t('form.add_choice')}
          </Button>
        )}
      </fieldset>
    );
  }

  numberFields(locked: boolean) {
    return (
      <fieldset className="Form-group PredictionModal-number" disabled={locked}>
        <div className="PredictionModal-numberRow">
          <label>
            {t('form.unit')}
            <input
              className="FormControl"
              maxlength={24}
              placeholder={tx('form.unit_placeholder')}
              value={this.unit}
              oninput={(e: InputEvent) => (this.unit = (e.target as HTMLInputElement).value)}
            />
          </label>
          <label>
            {t('form.tolerance')}
            <input
              className="FormControl"
              type="number"
              min="0"
              step="any"
              placeholder="0"
              value={this.tolerance}
              oninput={(e: InputEvent) => (this.tolerance = (e.target as HTMLInputElement).value)}
            />
          </label>
        </div>
        <p className="helpText">{t('form.tolerance_help')}</p>
      </fieldset>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const editing = this.attrs.prediction;
    const body: Record<string, any> = {
      title: this.question,
      description: this.description,
    };
    if (!editing || editing.status === 'open') {
      body.lockAt = new Date(this.lockAt).toISOString();
      if (!editing || editing.can.editWording) body.hidePicks = this.hidePicks;
    }
    if (editing && !editing.can.editWording) delete body.title;
    if (!editing || editing.can.editOptions) {
      body.type = this.type;
      if (this.type === 'choice') body.options = this.options;
      if (this.type === 'number') {
        body.unit = this.unit;
        body.tolerance = this.tolerance;
      }
    }
    if (!editing && this.attrs.discussionId) body.discussionId = this.attrs.discussionId;
    // Unchanged lock time on an edit: don't send it, so an hour-old form can't trip the "in the future" check.
    if (editing && body.lockAt && new Date(body.lockAt).getTime() === new Date(editing.lockAt).getTime()) delete body.lockAt;

    this.loading = true;
    save(editing ? editing.id : null, body)
      .then((p) => {
        app.alerts.show({ type: 'success' }, editing ? tx('form.saved') : tx('form.created'));
        this.attrs.onsave?.(p);
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
