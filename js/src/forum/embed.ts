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
 * any editor (it's just a link): inside a paragraph, or straight in the post
 * body (plain-text posts have no paragraphs). The link is swapped for the
 * card only once the card is loaded, so a slow or refused lookup leaves the
 * link. A swap keeps the post's node count the same, which Mithril's m.trust
 * needs to remove the post later; the post's very first node is the one it
 * keeps a reference to, so a link there stays a link.
 * Returns the ids it embedded, so the discussion's attached list can skip them.
 */
export default function scan(root: Element | null): number[] {
  if (!root || !app.forum.attribute('predictionsCanView')) return [];
  const found: Array<[HTMLAnchorElement, number]> = [];
  // Cards already in from an earlier scan still count as embedded.
  const ids: number[] = [...root.querySelectorAll<HTMLElement>('.Post-body .PredictionEmbed[data-id]')].map((el) => Number(el.dataset.id));

  root.querySelectorAll<HTMLAnchorElement>('.Post-body a[href*="/predictions/"]').forEach((a) => {
    // Our own cards (embedded, or attached under the first post) link to themselves.
    if (a.closest('.PredictionCard, .PredictionEmbed, .PredictionAttached')) return;
    const id = idOf(a);
    const parent = a.parentElement;
    if (id === null || !parent) return;
    if (parent.classList.contains('Post-body') && parent.firstChild === a) return;
    if (!edge(a.previousSibling, 'previousSibling') || !edge(a.nextSibling, 'nextSibling')) return;
    ids.push(id);
    if (a.hasAttribute('data-prediction-embed')) return;
    a.setAttribute('data-prediction-embed', '');
    found.push([a, id]);
  });

  // The card, its styles' data and the request are only loaded by a page that has one.
  if (found.length) import('./components').then(({ embedCard }) => found.forEach(([a, id]) => embedCard(a, id)));

  return ids;
}
