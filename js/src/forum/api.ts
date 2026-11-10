import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';

export type PredictionType = 'choice' | 'yesno' | 'number';
export type Status = 'open' | 'locked' | 'settled' | 'void';

export interface Person {
  id: number;
  username: string;
  displayName: string;
  slug: string;
  avatarUrl: string | null;
}

export interface Option {
  id: number;
  label: string;
  /** Null while the creator keeps picks hidden. */
  count: number | null;
  isCorrect: boolean | null;
}

export interface MyPick {
  optionId: number | null;
  value: number | null;
  hit: boolean | null;
  points: number | null;
}

export interface NumberStats {
  count: number;
  avg: number;
  median: number;
  min: number;
  max: number;
  bins: number[];
  closest?: Array<{ user: Person; value: number; points: number }>;
}

export interface Prediction {
  id: number;
  type: PredictionType;
  title: string;
  description: string | null;
  unit: string | null;
  tolerance: number | null;
  hidePicks: boolean;
  lockAt: string;
  status: Status;
  picksVisible: boolean;
  picksCount: number;
  options: Option[];
  stats: NumberStats | null;
  answer: number | null;
  correctOptionId: number | null;
  settledAt: string | null;
  scored: boolean;
  myPick: MyPick | null;
  user: Person | null;
  discussion: { id: number; title: string; slug: string } | null;
  url: string;
  createdAt: string;
  can: { pick: boolean; edit: boolean; editOptions: boolean; settle: boolean; delete: boolean };
}

export interface BoardRow {
  rank: number;
  user: Person;
  points: number;
  hits: number;
  settled: number;
  hitRate: number;
  streak: number | null;
  bestStreak: number;
}

export interface Season {
  id: number;
  name: string;
  startedAt: string;
  endedAt: string | null;
}

export interface Board {
  season: Season | null;
  currentSeasonId: number;
  seasons: Season[];
  rows: BoardRow[];
  me: BoardRow | null;
  oracles: Array<{ rank: number; points: number; user: Person }>;
}

export const t = (key: string, params: Record<string, any> = {}) => app.translator.trans(`ernestdefoe-predictions.forum.${key}`, params);
export const tx = (key: string, params: Record<string, any> = {}) => extractText(t(key, params));

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-predictions.forum.errors.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return tx('errors.generic');
}

export function call<T>(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any>): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + path;
  return app.request<T>({
    method,
    url: method === 'GET' && data ? url + '?' + query(data) : url,
    body: method === 'GET' ? undefined : data,
    errorHandler: (error: any) => {
      app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    },
  });
}

/** filter[status]=open style parameters from a nested object. */
function query(data: Record<string, any>, prefix = ''): string {
  return Object.entries(data)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => {
      const name = prefix ? `${prefix}[${key}]` : key;
      return typeof value === 'object' ? query(value, name) : `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`;
    })
    .join('&');
}

/**
 * Every prediction a page shows is fetched once and shared: the same card in
 * a post and on the discussion stays in step when someone picks. Embeds ask
 * for theirs together (one request for all the links on a page).
 */
const known = new Map<number, Prediction>();
let wanted = new Map<number, Array<(p: Prediction | null) => void>>();
let timer = 0;

export function remember(prediction: Prediction): Prediction {
  const existing = known.get(prediction.id);
  if (existing) {
    Object.assign(existing, prediction);
    return existing;
  }
  known.set(prediction.id, prediction);
  return prediction;
}

export function forget(id: number): void {
  known.delete(id);
}

export function lookup(id: number): Promise<Prediction | null> {
  const found = known.get(id);
  if (found) return Promise.resolve(found);

  return new Promise((resolve) => {
    wanted.set(id, [...(wanted.get(id) || []), resolve]);
    timer ||= window.setTimeout(flush, 0);
  });
}

function flush(): void {
  const batch = wanted;
  wanted = new Map();
  timer = 0;

  const ids = [...batch.keys()];
  for (let i = 0; i < ids.length; i += 50) {
    const chunk = ids.slice(i, i + 50);
    app
      .request<{ data: Prediction[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/predictions?' + query({ filter: { id: chunk.join(',') } }),
      })
      .then(
        ({ data }) => {
          data.forEach(remember);
          chunk.forEach((id) => batch.get(id)!.forEach((resolve) => resolve(known.get(id) || null)));
        },
        () => chunk.forEach((id) => batch.get(id)!.forEach((resolve) => resolve(null)))
      );
  }
}

export function list(filter: Record<string, any>, offset = 0, limit = 20) {
  return call<{ data: Prediction[]; more: boolean; meta: { canCreate: boolean } }>('GET', '/predictions', { filter, page: { offset, limit } }).then(
    (res) => ({ ...res, data: res.data.map(remember) })
  );
}

export function save(id: number | null, body: Record<string, any>): Promise<Prediction> {
  return call<{ data: Prediction }>(id ? 'PATCH' : 'POST', id ? `/predictions/${id}` : '/predictions', body).then((res) => remember(res.data));
}

export function pick(id: number, body: { optionId?: number; value?: number } | null): Promise<Prediction> {
  return call<{ data: Prediction }>(body ? 'POST' : 'DELETE', `/predictions/${id}/pick`, body || undefined).then((res) => remember(res.data));
}

export function settle(id: number, body: { optionId?: number; value?: number; void?: boolean }): Promise<Prediction> {
  return call<{ data: Prediction }>('POST', `/predictions/${id}/settle`, body).then((res) => remember(res.data));
}

export function remove(id: number): Promise<void> {
  return call<void>('DELETE', `/predictions/${id}`).then(() => forget(id));
}

export function predictionUrl(id: number): string {
  return app.forum.attribute<string>('baseUrl') + '/predictions/' + id;
}

/** "1,234.5" in the reader's language, keeping up to 2 decimals. */
export function num(value: number | null | undefined): string {
  if (value === null || value === undefined) return '';
  return new Intl.NumberFormat(app.translator.getLocale() || undefined, { maximumFractionDigits: 2 }).format(value);
}

export function percent(value: number): string {
  return new Intl.NumberFormat(app.translator.getLocale() || undefined, { style: 'percent', maximumFractionDigits: 0 }).format(value);
}

/** "2d 4h", "3h 12m", "45m", "under a minute" until the lock. */
export function countdown(iso: string): string {
  const ms = new Date(iso).getTime() - Date.now();
  if (ms <= 60000) return tx('time.soon');
  const minutes = Math.floor(ms / 60000);
  const days = Math.floor(minutes / 1440);
  const hours = Math.floor((minutes % 1440) / 60);
  const mins = minutes % 60;
  if (days) return tx('time.days_hours', { days, hours });
  if (hours) return tx('time.hours_minutes', { hours, minutes: mins });
  return tx('time.minutes', { minutes: mins });
}

export function when(iso: string): string {
  return new Intl.DateTimeFormat(app.translator.getLocale() || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
}

/** A yes/no prediction's two choices, in the reader's language (they're stored as "Yes" and "No"). */
export function optionLabel(prediction: Prediction, option: Option, index: number): string {
  return prediction.type === 'yesno' ? tx(index === 0 ? 'card.yes' : 'card.no') : option.label;
}

export function openForm(attrs: { prediction?: Prediction; discussionId?: number; onsave?: (p: Prediction) => void } = {}): void {
  app.modal.show(() => import('./components').then((ui) => ({ default: ui.PredictionModal })), attrs);
}
