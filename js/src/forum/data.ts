import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type User from 'flarum/common/models/User';
import type { ApiPayloadPlural, ApiPayloadSingle } from 'flarum/common/Store';
import type PredictionModel from './models/PredictionModel';
import type PickModel from './models/PickModel';
import { tx, type MyPick, type Person, type Prediction } from './api';

/*
 * Talking to the server, the shared card cache and formatting: only the lazy
 * chunk needs these, so they stay out of forum.js.
 */

/** The sentence for an error answer from our endpoints. */
export function errorText(error: any): string {
  const code = error?.response?.errors?.[0]?.code;
  const key = `ernestdefoe-predictions.forum.errors.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return tx('errors.generic');
}

type Doc = ApiPayloadSingle | ApiPayloadPlural;

export function call(method: 'GET' | 'POST' | 'PATCH' | 'DELETE', path: string, data?: Record<string, any>): Promise<any> {
  const url = app.forum.attribute<string>('apiUrl') + path;
  return app.request({
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

export function person(user: User | false | undefined | null): Person | null {
  if (!user) return null;
  return {
    id: Number(user.id()),
    username: user.username(),
    displayName: user.displayName(),
    slug: user.slug(),
    avatarUrl: user.avatarUrl() || null,
  };
}

/**
 * A stored prediction as the plain object cards read. Relationships only when the
 * response carried them (an action's response has the attributes alone), so a
 * refresh after a pick never blanks the creator or the discussion.
 */
function plain(model: PredictionModel): Prediction {
  const attributes = model.data.attributes as Record<string, any>;
  const related = model.data.relationships || {};
  const discussion = model.discussion();

  return {
    ...(attributes as Omit<Prediction, 'id' | 'type' | 'user' | 'discussion'>),
    id: Number(model.id()),
    type: attributes.kind,
    ...('user' in related ? { user: person(model.user()) } : {}),
    ...('discussion' in related
      ? { discussion: discussion ? { id: Number(discussion.id()), title: discussion.title(), slug: discussion.slug() } : null }
      : {}),
  } as Prediction;
}

/** A JSON:API answer pushed into the store and turned into plain predictions. */
function store(doc: Doc): Prediction[] {
  const models = app.store.pushPayload<PredictionModel[]>(doc as ApiPayloadPlural);
  return (Array.isArray(models) ? models : [models]).map((model) => remember(plain(model as PredictionModel)));
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
    const answer = (found: (id: number) => Prediction | null) => chunk.forEach((id) => batch.get(id)!.forEach((resolve) => resolve(found(id))));
    app
      .request<Doc>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/predictions?' + query({ filter: { id: chunk.join(',') }, page: { limit: 50 } }),
      })
      .then(
        (doc) => {
          store(doc);
          answer((id) => known.get(id) || null);
        },
        // A card that can't be loaded leaves its link a link.
        () => answer(() => null)
      );
  }
}

export function list(filter: Record<string, any>, offset = 0, limit = 20): Promise<{ data: Prediction[]; more: boolean }> {
  return call('GET', '/predictions', { filter, page: { offset, limit } }).then((doc: ApiPayloadPlural) => ({
    data: store(doc),
    more: !!doc.links?.next,
  }));
}

/** Open (id null) or edit a prediction. The form's `type` is sent as `kind`: JSON:API reserves `type`. */
export function save(id: number | null, body: Record<string, any>): Promise<Prediction> {
  const { type, ...rest } = body;
  const attributes = type === undefined ? rest : { ...rest, kind: type };
  const data = { type: 'predictions', ...(id ? { id: String(id) } : {}), attributes };

  return call(id ? 'PATCH' : 'POST', id ? `/predictions/${id}` : '/predictions', { data }).then((doc: Doc) => store(doc)[0]);
}

export function pick(id: number, body: { optionId?: number; value?: number } | null): Promise<Prediction> {
  return call(body ? 'POST' : 'DELETE', `/predictions/${id}/pick`, body || undefined).then((doc: Doc) => store(doc)[0]);
}

export function settle(id: number, body: { optionId?: number; value?: number; void?: boolean }): Promise<Prediction> {
  return call('POST', `/predictions/${id}/settle`, body).then((doc: Doc) => store(doc)[0]);
}

export function remove(id: number): Promise<void> {
  return call('DELETE', `/predictions/${id}`).then(() => forget(id));
}

/** Who picked what, best results first, once picks are out in the open. */
export function pickers(id: number): Promise<Array<MyPick & { user: Person }>> {
  return call('GET', '/prediction-picks', { filter: { prediction: id }, include: 'user' }).then((doc: ApiPayloadPlural) =>
    app.store
      .pushPayload<PickModel[]>(doc)
      .map((m) => ({ optionId: m.optionId(), value: m.value(), hit: m.hit(), points: m.points(), user: person(m.user()) }))
      .filter((row): row is MyPick & { user: Person } => row.user !== null)
  );
}

/** "1,234.5" in the reader's language, keeping up to 2 decimals. */
export function num(value: number | null | undefined): string {
  if (value === null || value === undefined) return '';
  return new Intl.NumberFormat(app.translator.getLocale() || undefined, { maximumFractionDigits: 2 }).format(value);
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
