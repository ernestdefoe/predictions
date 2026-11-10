import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { optionLabel, t, tx, type Prediction } from '../api';
import { num, settle } from '../data';

/**
 * What happened: the right choice, or the number. Settling an open one also
 * locks it. Settling again corrects it, and everyone's points follow.
 */

declare const m: Mithril.Static;

export default class SettleModal extends FormModal<IFormModalAttrs & { prediction: Prediction }> {
  optionId: number | null = null;
  value = '';

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const p = this.attrs.prediction;
    this.optionId = p.correctOptionId;
    this.value = p.answer !== null ? String(p.answer) : '';
  }

  className() {
    return 'PredictionSettleModal Modal--small';
  }

  title() {
    return tx('settle.title');
  }

  content() {
    const p = this.attrs.prediction;
    return (
      <div className="Modal-body">
        <div className="Form">
          <p className="PredictionSettleModal-question">{p.title}</p>
          {p.status === 'open' && <p className="helpText">{t('settle.early')}</p>}
          {p.status === 'settled' && <p className="helpText">{t('settle.correcting')}</p>}
          {p.myPick && <p className="helpText">{t('settle.own_pick')}</p>}

          {p.type === 'number' ? (
            <div className="Form-group">
              <label for="prediction-answer">{t('settle.answer', { unit: p.unit || '' })}</label>
              <input
                id="prediction-answer"
                className="FormControl"
                type="number"
                step="any"
                inputmode="decimal"
                required
                value={this.value}
                oninput={(e: InputEvent) => (this.value = (e.target as HTMLInputElement).value)}
              />
              {p.stats && <p className="helpText">{t('settle.guesses', { count: p.stats.count, min: num(p.stats.min), max: num(p.stats.max) })}</p>}
            </div>
          ) : (
            <fieldset className="Form-group PredictionSettleModal-options">
              <legend>{t('settle.which')}</legend>
              {p.options.map((option, i) => (
                <label className={'PredictionSettleModal-option' + (this.optionId === option.id ? ' is-active' : '')}>
                  <input type="radio" name="prediction-answer" checked={this.optionId === option.id} onchange={() => (this.optionId = option.id)} />
                  {optionLabel(p, option, i)}
                </label>
              ))}
            </fieldset>
          )}

          <div className="Form-group">
            <Button
              className="Button Button--primary Button--block"
              type="submit"
              loading={this.loading}
              disabled={p.type === 'number' ? this.value.trim() === '' : this.optionId === null}
            >
              {t('settle.submit')}
            </Button>
          </div>
          {p.status !== 'void' && p.can.void && (
            <Button className="Button Button--link PredictionSettleModal-void" onclick={() => this.submit({ void: true })}>
              {t('settle.void')}
            </Button>
          )}
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    const p = this.attrs.prediction;
    this.submit(p.type === 'number' ? { value: parseFloat(this.value.replace(',', '.')) } : { optionId: this.optionId! });
  }

  submit(body: { optionId?: number; value?: number; void?: boolean }) {
    if (body.void && !confirm(tx('settle.void_confirm'))) return;
    this.loading = true;
    settle(this.attrs.prediction.id, body)
      .then(() => {
        app.alerts.show({ type: 'success' }, body.void ? tx('settle.voided') : tx('settle.done'));
        this.hide();
      })
      .catch(() => {})
      .finally(() => {
        this.loading = false;
        m.redraw();
      });
  }
}
