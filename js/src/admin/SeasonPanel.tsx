import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

declare const m: Mithril.Static;

const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-predictions.admin.season.${key}`, params);

interface Season {
  id: number;
  name: string;
  startedAt: string;
  endedAt: string | null;
}

/**
 * The season box: what's running, since when, and "End this season": its
 * top predictors become Oracles and the leaderboard starts fresh.
 */
export default class SeasonPanel extends Component {
  seasons: Season[] | null = null;
  current: number | null = null;
  leaders: Array<{ user: { displayName: string }; points: number }> = [];
  name = '';
  busy = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    app.request<any>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/prediction-leaderboard?limit=5' }).then((board) => {
      this.seasons = board.seasons;
      this.current = board.currentSeasonId;
      this.leaders = board.rows;
      this.name = extractText(t('next_default', { number: board.seasons.length + 1 }));
      m.redraw();
    });
  }

  view() {
    if (!this.seasons) return <LoadingIndicator />;
    const current = this.seasons.find((s) => s.id === this.current);
    const date = (iso: string) => new Date(iso).toLocaleDateString();
    const count = Number(app.data.settings['ernestdefoe-predictions.oracle_count'] ?? 3);

    return (
      <div className="Form-group PredictionsSeasonPanel">
        <label>{t('heading')}</label>
        {current && <p>{t('current', { name: current.name, date: date(current.startedAt) })}</p>}
        {this.leaders.length ? (
          <p className="helpText">
            {t('leaders', {
              names: this.leaders
                .slice(0, count)
                .map((r) => `${r.user.displayName} (${r.points})`)
                .join(', '),
            })}
          </p>
        ) : (
          <p className="helpText">{t('no_leaders')}</p>
        )}
        <div className="PredictionsSeasonPanel-row">
          <input
            className="FormControl"
            maxlength={80}
            aria-label={extractText(t('next_name'))}
            placeholder={extractText(t('next_name'))}
            value={this.name}
            oninput={(e: InputEvent) => (this.name = (e.target as HTMLInputElement).value)}
          />
          <Button className="Button Button--danger" loading={this.busy} disabled={!this.name.trim()} onclick={() => this.end()}>
            {t('end')}
          </Button>
        </div>
        <p className="helpText">{t('end_help')}</p>
        {this.seasons.length > 1 && (
          <ul className="PredictionsSeasonPanel-past">
            {this.seasons
              .filter((s) => s.endedAt)
              .map((s) => (
                <li>{t('past', { name: s.name, from: date(s.startedAt), to: date(s.endedAt!) })}</li>
              ))}
          </ul>
        )}
      </div>
    );
  }

  end() {
    if (!confirm(extractText(t('confirm')))) return;
    this.busy = true;
    app
      .request<any>({ method: 'POST', url: app.forum.attribute('apiUrl') + '/prediction-seasons', body: { name: this.name } })
      .then((res) => {
        app.alerts.show({ type: 'success' }, t('ended', { count: res.oracles }));
        this.load();
      })
      .catch(() => {})
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
