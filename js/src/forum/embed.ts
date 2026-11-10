import app from 'flarum/forum/app';

let prefix: string | null = null;

/** "https://forum.example/predictions/" — where this forum's prediction links point. */
function predictionsPrefix(): string {
  if (prefix === null) {
    const base = new URL(app.forum.attribute<string>('baseUrl'), location.href);
    prefix = base.origin + base.pathname.replace(/\/$/, '') + '/predictions/';
  }
  return prefix;
}

/** The id a link to one of this forum's predictions names, else null. */
export function idOf(a: HTMLAnchorElement): number | null {
  const href = a.href.split(/[?#]/)[0].replace(/\/$/, '');
  const p = predictionsPrefix();
  if (!href.startsWith(p)) return null;
  const rest = href.slice(p.length);
  return /^\d+$/.test(rest) ? parseInt(rest, 10) : null;
}

/** Nothing but whitespace between the node and the edge of its line in that direction. */
function edge(node: Node | null, step: 'previousSibling' | 'nextSibling'): boolean {
  while (node && node.nodeType === Node.TEXT_NODE && !node.textContent!.trim()) node = node[step];
  return !node || node.nodeName === 'BR';
}

/**
 * A prediction link on a line of its own becomes the prediction's card, in
 * any editor (it's just a link). Only the link is replaced, inside its
 * paragraph: the post's top-level nodes belong to Mithril's m.trust.
 * Returns the ids it embedded, so the discussion's attached list can skip them.
 */
export default function scan(root: Element | null): number[] {
  if (!root || !app.forum.attribute('predictionsCanView')) return [];
  const found: Array<[HTMLElement, number]> = [];
  const ids: number[] = [];

  root.querySelectorAll<HTMLAnchorElement>('.Post-body a[href*="/predictions/"]').forEach((a) => {
    const id = idOf(a);
    if (id === null) return;
    ids.push(id);
    if (a.hasAttribute('data-prediction-embed')) return;
    a.setAttribute('data-prediction-embed', '');

    const parent = a.parentElement;
    if (!parent || parent.classList.contains('Post-body')) return;
    if (!edge(a.previousSibling, 'previousSibling') || !edge(a.nextSibling, 'nextSibling')) return;

    const holder = document.createElement('span');
    holder.className = 'PredictionEmbed';
    found.push([holder, id]);
    // The link stays (hidden) until the card is in, so a slow or refused lookup still leaves a link.
    a.after(holder);
    a.classList.add('PredictionEmbed-link');
  });

  // The card, its styles' data and the request are only loaded by a page that has one.
  if (found.length) import('./components').then(({ mountCards }) => found.forEach(([holder, id]) => mountCards(holder, [id], 'post')));

  return ids;
}
