import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-predictions.admin.season.${key}`, params);

interface Season {
  id: number;
  name: string;
  startedAt: string;
  endedAt: string | null;
}

/** GET /api/prediction-standings, the parts this panel reads. */
interface Standings {
  data: Array<{ attributes: { points: number }; relationships: { user: { data: { id: string } } } }>;
  included?: Array<{ id: string; attributes: { displayName: string } }>;
  meta: { seasons: Season[]; currentSeasonId: number | null };
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
    app
      .request<Standings>({ method: 'GET', url: app.forum.attribute('apiUrl') + '/prediction-standings?page[limit]=5&page[total]=0' })
      .then((doc) => {
        // The admin bundle has no store model for standings: names come from the included members.
        const names = new Map((doc.included || []).map((user) => [user.id, user.attributes.displayName]));
        this.seasons = doc.meta.seasons;
        this.current = doc.meta.currentSeasonId;
        this.leaders = doc.data.map((row) => ({
          user: { displayName: names.get(row.relationships.user.data.id) || '' },
          points: row.attributes.points,
        }));
        this.name = extractText(t('next_default', { number: doc.meta.seasons.length + 1 }));
        m.redraw();
      })
      .catch(() => {
        /* app.request has already shown the error */
      });
  }

  view() {
    if (!this.seasons) return <LoadingIndicator />;
    const current = this.seasons.find((s) => s.id === this.current);
    const date = (iso: string) => new Date(iso).toLocaleDateString();
    const count = Number(app.data.settings['ernestdefoe-predictions.oracle_count']);

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
      .request<{ data: { attributes: { oraclesNamed: number } } }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/prediction-seasons',
        body: { data: { type: 'prediction-seasons', attributes: { name: this.name } } },
      })
      .then((res) => {
        app.alerts.show({ type: 'success' }, t('ended', { count: res.data.attributes.oraclesNamed }));
        this.load();
      })
      .catch(() => {
        /* app.request has already shown the error */
      })
      .finally(() => {
        this.busy = false;
        m.redraw();
      });
  }
}
