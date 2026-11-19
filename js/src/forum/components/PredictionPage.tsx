import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Icon from 'flarum/common/components/Icon';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import PredictionCard, { PersonLink } from './PredictionCard';
import { PageStructure, IndexSidebar } from './PredictionsPage';
import { optionLabel, t, tx, type MyPick, type Option, type Person, type Prediction } from '../api';
import { lookup, num, pickers } from '../data';

/** /predictions/:id: one prediction, and who picked what once picks are out in the open. */

type PickRow = MyPick & { user: Person };

export default class PredictionPage extends Page {
  prediction: Prediction | null = null;
  missing = false;
  picks: PickRow[] | null = null;
  morePicks = false;
  loadingPicks = false;
  picksFor = '';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--predictions';
    const id = parseInt(m.route.param('id'), 10);
    lookup(id).then((p) => {
      this.prediction = p;
      this.missing = !p;
      if (p) {
        app.setTitle(p.title);
        app.history.push('prediction', p.title);
      }
      m.redraw();
    });
  }

  onbeforeupdate() {
    // Load (or reload) who picked what when picks become visible or a pick changes the count.
    const p = this.prediction;
    const key = p ? `${p.id}:${p.picksVisible}:${p.picksCount}:${p.status}:${p.scored}` : '';
    if (p && p.picksVisible && key !== this.picksFor) {
      this.picksFor = key;
      this.loadPicks(p, 0);
    }
  }

  /** A page of who picked what (100 at a time), from the start or after the ones shown. */
  loadPicks(p: Prediction, offset: number) {
    this.loadingPicks = true;
    pickers(p.id, offset)
      .then(({ rows, more }) => {
        this.picks = offset ? [...(this.picks || []), ...rows] : rows;
        this.morePicks = more;
      })
      .catch(() => {
        /* call() has already shown the error */
      })
      .finally(() => {
        this.loadingPicks = false;
        m.redraw();
      });
  }

  view() {
    const p = this.prediction;
    return (
      <PageStructure className="PredictionPage" sidebar={() => <IndexSidebar />}>
        <Link className="PredictionPage-back" href={app.route('predictions')}>
          <Icon name="fas fa-arrow-left" /> {t('page.all')}
        </Link>
        {this.missing ? (
          <p className="PredictionsPage-empty">{t('page.missing')}</p>
        ) : !p ? (
          <LoadingIndicator />
        ) : (
          [<PredictionCard prediction={p} context="single" ondelete={() => m.route.set(app.route('predictions'))} />, this.whoPicked(p)]
        )}
      </PageStructure>
    );
  }

  whoPicked(p: Prediction) {
    if (!p.picksVisible) return <p className="PredictionPage-hidden">{t('single.hidden')}</p>;
    if (!this.picks) return null;
    if (!this.picks.length) return <p className="PredictionPage-hidden">{t('single.none')}</p>;

    return (
      <section className="PredictionWho">
        <h3>{t('single.who')}</h3>
        {p.type === 'number' ? this.guesses(p, this.picks) : p.options.map((option, i) => this.group(p, option, i, this.picks!))}
        {this.morePicks && (
          <Button className="Button PredictionWho-more" loading={this.loadingPicks} onclick={() => this.loadPicks(p, this.picks!.length)}>
            {t('single.more')}
          </Button>
        )}
      </section>
    );
  }

  /** Every guess, nearest first. */
  guesses(p: Prediction, picks: PickRow[]) {
    const unit = p.unit ? ` ${p.unit}` : '';
    return (
      <ol className="PredictionWho-list">
        {picks.map((row) => (
          <li className={row.hit ? 'is-hit' : ''}>
            <PersonLink person={row.user} />
            <span className="PredictionWho-value">{num(row.value) + unit}</span>
            {row.points ? <span className="PredictionPoints">+{row.points}</span> : null}
          </li>
        ))}
      </ol>
    );
  }

  /** One choice and the members who picked it (those loaded so far; the count is the whole choice's). */
  group(p: Prediction, option: Option, index: number, picks: PickRow[]) {
    const rows = picks.filter((r) => r.optionId === option.id);
    return (
      <div className={'PredictionWho-group' + (option.isCorrect ? ' is-correct' : '')}>
        <h4>
          {option.isCorrect && <Icon name="fas fa-circle-check" />} {optionLabel(p, option, index)}{' '}
          <span className="PredictionWho-count">{tx('card.picks_short', { count: option.count ?? rows.length })}</span>
        </h4>
        {rows.length ? (
          <ul className="PredictionWho-people">
            {rows.map((row) => (
              <li>
                <PersonLink person={row.user} />
                {row.points ? <span className="PredictionPoints">+{row.points}</span> : null}
              </li>
            ))}
          </ul>
        ) : (option.count ?? 0) === 0 ? (
          <p className="PredictionWho-nobody">{t('single.nobody')}</p>
        ) : null}
      </div>
    );
  }
}
