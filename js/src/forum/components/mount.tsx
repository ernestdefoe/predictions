import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import PredictionCard from './PredictionCard';
import { lookup, type Prediction } from '../api';

declare const m: Mithril.Static;

type Context = 'post' | 'discussion';

/**
 * Cards inside a post or under a discussion's first post are their own
 * Mithril roots (m.mount), not children of the post: the post's subtree
 * retainer skips redraws until the POST changes, so a child card would never
 * show its data or a new pick. A root is redrawn by every m.redraw(), and
 * unmounts itself once the post has replaced the element it lives in.
 */
class Cards extends Component<{ ids: number[]; context: Context; host: Element }> {
  found: Prediction[] | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    Promise.all(this.attrs.ids.map(lookup)).then((all) => {
      this.found = all.filter((p): p is Prediction => !!p);
      m.redraw();
    });
  }

  view() {
    if (!this.attrs.host.isConnected) {
      window.setTimeout(() => m.mount(this.attrs.host, null), 0);
      return null;
    }
    if (!this.found) return this.attrs.context === 'discussion' ? <LoadingIndicator display="block" size="small" /> : null;
    return this.found.map((p) => (
      <PredictionCard key={p.id} prediction={p} context={this.attrs.context} ondelete={() => (this.found = this.found!.filter((f) => f !== p))} />
    ));
  }
}

export function mountCards(host: Element, ids: number[], context: Context): void {
  m.mount(host, { view: () => <Cards ids={ids} context={context} host={host} /> });
}
