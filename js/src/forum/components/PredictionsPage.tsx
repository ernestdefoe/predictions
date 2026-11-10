import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Avatar from 'flarum/common/components/Avatar';
import Link from 'flarum/common/components/Link';
import Select from 'flarum/common/components/Select';
import type Mithril from 'mithril';
import PredictionCard, { fakeUser } from './PredictionCard';
import { openForm, percent, t, tx, type Board, type BoardRow, type Prediction } from '../api';
import { call, list, num } from '../data';

/**
 * /predictions: the open ones (soonest lock first), the ones waiting on a
 * result, the settled ones, and the season leaderboard. Built in core's
 * page structure with the forum's own sidebar, so any theme dresses it.
 */

declare const m: Mithril.Static;
declare const flarum: any;

type Tab = 'open' | 'locked' | 'settled' | 'leaderboard';
const TABS: Tab[] = ['open', 'locked', 'settled', 'leaderboard'];
const PAGE = 20;

/** Core components this page builds on, loaded through the registry: some live in lazy chunks. */
export let PageStructure: any;
export let IndexSidebar: any;

export async function loadCore(): Promise<void> {
  const [structure, sidebar] = await Promise.all([
    flarum.reg.asyncModuleImport('flarum/forum/components/PageStructure'),
    flarum.reg.asyncModuleImport('flarum/forum/components/IndexSidebar'),
  ]);
  PageStructure = structure.default || structure;
  IndexSidebar = sidebar.default || sidebar;
}

export default class PredictionsPage extends Page {
  tab: Tab = 'open';
  mine = '';
  items: Prediction[] = [];
  more = false;
  loading = true;
  canCreate = !!app.forum.attribute('predictionsCanCreate');
  board: Board | null = null;
  boardSeason: string = '';
  boardLoading = false;
  private request = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const tab = m.route.param('tab') as Tab;
    if (TABS.includes(tab)) this.tab = tab;
    app.history.push('predictions', tx('page.title'));
    this.bodyClass = 'App--predictions';
    this.loadBoard();
    if (this.tab !== 'leaderboard') this.load(0);
  }

  oncreate(vnode: Mithril.VnodeDOM) {
    super.oncreate(vnode);
    app.setTitle(tx('page.title'));
    app.setTitleCount(0);
  }

  load(offset: number) {
    const ticket = ++this.request;
    this.loading = true;
    list({ status: this.tab, mine: this.mine || undefined }, offset, PAGE)
      .then((res) => {
        if (ticket !== this.request) return;
        this.items = offset ? this.items.concat(res.data) : res.data;
        this.more = res.more;
        this.canCreate = res.meta.canCreate;
      })
      .catch(() => {})
      .finally(() => {
        if (ticket === this.request) this.loading = false;
        m.redraw();
      });
  }

  loadBoard() {
    this.boardLoading = true;
    call<Board>('GET', '/prediction-leaderboard', this.boardSeason ? { season: this.boardSeason } : undefined)
      .then((board) => (this.board = board))
      .catch(() => {})
      .finally(() => {
        this.boardLoading = false;
        m.redraw();
      });
  }

  switchTab(tab: Tab) {
    if (tab === this.tab) return;
    this.tab = tab;
    this.items = [];
    const url = app.route('predictions') + (tab === 'open' ? '' : '?tab=' + tab);
    window.history.replaceState(window.history.state, '', url);
    if (tab !== 'leaderboard') this.load(0);
  }

  create() {
    openForm({
      onsave: (p) => {
        if (this.tab !== 'open') this.switchTab('open');
        else this.items = [p, ...this.items.filter((i) => i.id !== p.id)];
        m.redraw();
      },
    });
  }

  view() {
    return (
      <PageStructure className="PredictionsPage" sidebar={() => <IndexSidebar />}>
        <div className="PredictionsPage-header">
          <div className="PredictionsPage-heading">
            <h2 className="PredictionsPage-title">
              <Icon name="fas fa-wand-magic-sparkles" /> {t('page.title')}
            </h2>
            <p className="PredictionsPage-lead">{t('page.lead')}</p>
          </div>
          {this.canCreate && (
            <Button className="Button Button--primary PredictionsPage-new" icon="fas fa-plus" onclick={() => this.create()}>
              {t('page.new')}
            </Button>
          )}
        </div>

        {this.meStrip()}

        <nav className="PredictionsPage-tabs" role="tablist" aria-label={tx('page.title')}>
          {TABS.map((tab) => (
            <button
              type="button"
              role="tab"
              aria-selected={this.tab === tab ? 'true' : 'false'}
              className={'PredictionsPage-tab' + (this.tab === tab ? ' is-active' : '')}
              onclick={() => this.switchTab(tab)}
            >
              {t(`page.tabs.${tab}`)}
            </button>
          ))}
        </nav>

        {this.tab === 'leaderboard' ? this.leaderboard() : this.listView()}
      </PageStructure>
    );
  }

  meStrip() {
    const me = this.board?.me;
    if (!me || this.board?.season?.id !== this.board?.currentSeasonId) return null;
    return (
      <div className="PredictionsPage-me">
        <Avatar user={fakeUser(me.user)} className="PredictionAvatar PredictionAvatar--small" />
        <span className="PredictionsPage-meText">
          {t('page.me', { hits: me.hits, settled: me.settled, rate: percent(me.hitRate), season: this.board!.season!.name })}
        </span>
        {me.streak && me.streak > 1 ? (
          <span className="PredictionStreak">
            <Icon name="fas fa-fire" /> {t('page.streak', { count: me.streak })}
          </span>
        ) : null}
        <span className="PredictionsPage-meRank">{t('page.rank', { rank: me.rank })}</span>
        <span className="PredictionPoints">{t('page.points', { count: me.points })}</span>
      </div>
    );
  }

  listView() {
    return (
      <div className="PredictionsPage-list">
        {app.session.user && (
          <div className="PredictionsPage-toolbar">
            <Select
              aria-label={tx('page.filter_label')}
              value={this.mine}
              options={{ '': tx('page.filter.everyone'), picked: tx('page.filter.picked'), created: tx('page.filter.created') }}
              onchange={(value: string) => {
                this.mine = value;
                this.load(0);
              }}
            />
          </div>
        )}

        {this.items.map((p) => (
          <PredictionCard key={p.id} prediction={p} ondelete={() => (this.items = this.items.filter((i) => i !== p))} />
        ))}

        {!this.loading && !this.items.length && (
          <div className="PredictionsPage-empty">
            <Icon name="fas fa-wand-magic-sparkles" />
            <p>{t(`page.empty.${this.tab}`)}</p>
            {this.tab === 'open' && this.canCreate && (
              <Button className="Button" icon="fas fa-plus" onclick={() => this.create()}>
                {t('page.new')}
              </Button>
            )}
          </div>
        )}

        {this.loading ? (
          <LoadingIndicator />
        ) : this.more ? (
          <div className="PredictionsPage-more">
            <Button className="Button" onclick={() => this.load(this.items.length)}>
              {t('page.load_more')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  leaderboard() {
    const board = this.board;
    if (!board) return <LoadingIndicator />;

    return (
      <div className="PredictionBoard">
        <div className="PredictionBoard-toolbar">
          <Select
            aria-label={tx('board.season')}
            value={this.boardSeason || String(board.season?.id ?? 'all')}
            options={Object.fromEntries([
              ...board.seasons.map((s) => [String(s.id), s.endedAt ? s.name : tx('board.current', { name: s.name })]),
              ['all', tx('board.all_time')],
            ])}
            onchange={(value: string) => {
              this.boardSeason = value;
              this.loadBoard();
            }}
          />
          <details className="PredictionBoard-how">
            <summary>{t('board.how')}</summary>
            <ul>
              <li>{t(app.forum.attribute('predictionsBoldBonus') ? 'board.how_choice_bold' : 'board.how_choice')}</li>
              <li>{t('board.how_number')}</li>
              <li>{t('board.how_rank')}</li>
            </ul>
          </details>
        </div>

        {board.oracles.length > 0 && (
          <div className="PredictionBoard-oracles">
            <Icon name="fas fa-wand-magic-sparkles" /> {t('board.oracles', { season: board.season?.name })}{' '}
            {board.oracles.map((o) => (
              <Link className="PredictionBoard-oracle" href={app.route('user', { username: o.user.slug })}>
                <Avatar user={fakeUser(o.user)} className="PredictionAvatar" /> {o.user.displayName}
              </Link>
            ))}
          </div>
        )}

        {this.boardLoading ? (
          <LoadingIndicator />
        ) : board.rows.length ? (
          <ol className="PredictionBoard-rows">
            {board.rows.map((row) => this.boardRow(row, row.user.id === board.me?.user.id))}
            {board.me &&
              !board.rows.some((r) => r.user.id === board.me!.user.id) && [
                <li className="PredictionBoard-gap" aria-hidden="true">
                  ⋯
                </li>,
                this.boardRow(board.me, true),
              ]}
          </ol>
        ) : (
          <div className="PredictionsPage-empty">
            <Icon name="fas fa-trophy" />
            <p>{t('board.empty')}</p>
          </div>
        )}
      </div>
    );
  }

  boardRow(row: BoardRow, isMe: boolean) {
    return (
      <li className={'PredictionBoard-row' + (row.rank <= 3 ? ` is-top is-top-${row.rank}` : '') + (isMe ? ' is-me' : '')}>
        <span className="PredictionBoard-rank">
          {row.rank <= 3 ? <Icon name="fas fa-medal" /> : null}
          {row.rank}
        </span>
        <Link className="PredictionBoard-member" href={app.route('user', { username: row.user.slug })}>
          <Avatar user={fakeUser(row.user)} className="PredictionAvatar PredictionAvatar--small" />
          <span className="PredictionBoard-name">{row.user.displayName}</span>
        </Link>
        <span className="PredictionBoard-rate" title={tx('board.record', { hits: row.hits, settled: row.settled })}>
          <span className="PredictionBoard-rateBar" aria-hidden="true">
            <span style={{ width: `${Math.round(row.hitRate * 100)}%` }} />
          </span>
          <span className="PredictionBoard-rateText">
            {percent(row.hitRate)} <span className="PredictionBoard-record">{t('board.record', { hits: row.hits, settled: row.settled })}</span>
          </span>
        </span>
        <span className="PredictionBoard-streak">
          {row.streak && row.streak > 1 ? (
            <span className="PredictionStreak">
              <Icon name="fas fa-fire" /> {row.streak}
            </span>
          ) : null}
        </span>
        <span className="PredictionBoard-points">
          <strong>{num(row.points)}</strong> <span className="PredictionBoard-pointsLabel">{t('board.pts')}</span>
        </span>
      </li>
    );
  }
}
