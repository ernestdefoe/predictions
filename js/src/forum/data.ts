import app from 'flarum/forum/app';
import extractText from 'flarum/common/utils/extractText';
import type User from 'flarum/common/models/User';
import type RequestError from 'flarum/common/utils/RequestError';
import type { ApiPayloadPlural, ApiPayloadSingle } from 'flarum/common/Store';
import type PredictionModel from './models/PredictionModel';
import type PickModel from './models/PickModel';
import type StandingModel from './models/StandingModel';
import { tx, type Board, type BoardRow, type MyPick, type Person, type Prediction, type Season } from './api';

/*
 * Talking to the server, the shared card cache and formatting: only the lazy
 * chunk needs these, so they stay out of forum.js.
 */

/** The sentence for an error answer from our endpoints (a field's own rule says what's wrong in `detail`). */
export function errorText(error: RequestError): string {
  const first = error?.response?.errors?.[0];
  const code = first?.code;
  const key = `ernestdefoe-predictions.forum.errors.${code}`;
  if (code && extractText(app.translator.trans(key)) !== key) return extractText(app.translator.trans(key));
  if (code === 'validation_error' && first?.detail) return String(first.detail);
  if (error?.status === 0) return extractText(app.translator.trans('core.lib.error.network_message'));
  if (error?.status === 403) return extractText(app.translator.trans('core.lib.error.permission_denied_message'));
  return tx('errors.generic');
}

type Doc = ApiPayloadSingle | ApiPayloadPlural;

/**
 * A request to our endpoints. Every write to a prediction takes its lock without
 * waiting, so a write that meets another one at the same instant is answered 409
 * `predictions_busy`: it's tried once more a moment later before the reader is told.
 */
export function call<T>(
  method: 'GET' | 'POST' | 'PATCH' | 'DELETE',
  path: string,
  data?: Record<string, unknown>,
  retry = method !== 'GET'
): Promise<T> {
  const url = app.forum.attribute<string>('apiUrl') + path;
  return app
    .request<T>({
      method,
      url: method === 'GET' && data ? url + '?' + query(data) : url,
      body: method === 'GET' ? undefined : data,
      errorHandler: (error: RequestError) => {
        throw error;
      },
    })
    .catch((error: RequestError) => {
      if (retry && error?.response?.errors?.[0]?.code === 'predictions_busy') {
        return new Promise((resolve) => window.setTimeout(resolve, 400)).then(() => call<T>(method, path, data, false));
      }
      app.alerts.show({ type: 'error' }, errorText(error));
      throw error;
    });
}

/** filter[status]=open style parameters from a nested object. */
function query(data: Record<string, unknown>, prefix = ''): string {
  return Object.entries(data)
    .filter(([, value]) => value !== undefined && value !== null && value !== '')
    .map(([key, value]) => {
      const name = prefix ? `${prefix}[${key}]` : key;
      return typeof value === 'object'
        ? query(value as Record<string, unknown>, name)
        : `${encodeURIComponent(name)}=${encodeURIComponent(String(value))}`;
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
  const attributes = model.data.attributes as Record<string, unknown>;
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

export function list(filter: Record<string, unknown>, offset = 0, limit = 20): Promise<{ data: Prediction[]; more: boolean }> {
  return call<ApiPayloadPlural>('GET', '/predictions', { filter, page: { offset, limit } }).then((doc) => ({
    data: store(doc),
    more: !!doc.links?.next,
  }));
}

/**
 * Open (id null) or edit a prediction. The form's `type` is sent as `kind` (JSON:API
 * reserves `type`), and the discussion it belongs to as a relationship, only when opening.
 */
export function save(id: number | null, body: Record<string, unknown>): Promise<Prediction> {
  const { type, discussionId, ...rest } = body;
  const attributes = type === undefined ? rest : { ...rest, kind: type };
  const relationships = !id && discussionId ? { discussion: { data: { type: 'discussions', id: String(discussionId) } } } : undefined;
  const data = { type: 'predictions', ...(id ? { id: String(id) } : {}), attributes, ...(relationships ? { relationships } : {}) };

  return call<Doc>(id ? 'PATCH' : 'POST', id ? `/predictions/${id}` : '/predictions', { data }).then((doc) => store(doc)[0]);
}

export function pick(id: number, body: { optionId?: number; value?: number } | null): Promise<Prediction> {
  return call<Doc>(body ? 'POST' : 'DELETE', `/predictions/${id}/pick`, body || undefined).then((doc) => store(doc)[0]);
}

export function settle(id: number, body: { optionId?: number; value?: number; void?: boolean }): Promise<Prediction> {
  return call<Doc>('POST', `/predictions/${id}/settle`, body).then((doc) => store(doc)[0]);
}

export function remove(id: number): Promise<void> {
  return call<null>('DELETE', `/predictions/${id}`).then(() => forget(id));
}

/** Who picked what, best results first, once picks are out in the open: a page at a time. */
export function pickers(id: number, offset = 0, limit = 100): Promise<{ rows: Array<MyPick & { user: Person }>; more: boolean }> {
  return call<ApiPayloadPlural>('GET', '/prediction-picks', {
    filter: { prediction: id },
    include: 'user',
    page: { offset, limit, total: 0 },
  }).then((doc) => ({
    rows: app.store
      .pushPayload<PickModel[]>(doc)
      .map((row) => ({ optionId: row.optionId(), value: row.value(), hit: row.hit(), points: row.points(), user: person(row.user()) }))
      .filter((row): row is MyPick & { user: Person } => row.user !== null),
    more: !!doc.links?.next,
  }));
}

function standing(row: StandingModel): BoardRow | null {
  const user = person(row.user());
  return (
    user && {
      rank: row.rank(),
      user,
      points: row.points(),
      hits: row.hits(),
      settled: row.settled(),
      hitRate: row.hitRate(),
      streak: row.streak(),
      bestStreak: row.bestStreak(),
      oracle: row.oracle(),
    }
  );
}

/**
 * The leaderboard for a season ('all' for all time, undefined for the running one), and
 * the viewer's own row and rank in it (a second, one-row request), even when off the board.
 */
export function board(season?: string, limit = 50): Promise<Board> {
  const ask = <T>(filter: Record<string, unknown>, size: number) =>
    call<T>('GET', '/prediction-standings', { filter, page: { limit: size, total: 0 } });
  const me = app.session.user;

  return ask<ApiPayloadPlural & { meta: { season: Season | null; currentSeasonId: number | null; seasons: Season[] } }>({ season }, limit).then(
    (doc) => {
      const rows = app.store
        .pushPayload<StandingModel[]>(doc)
        .map(standing)
        .filter((row): row is BoardRow => row !== null);
      const result: Board = { ...doc.meta, rows, me: null, oracles: rows.filter((row) => row.oracle) };
      const mine = me && rows.find((row) => row.user.id === Number(me.id()));
      if (!me || mine) return { ...result, me: mine || null };

      return ask<ApiPayloadPlural>({ season: season ?? doc.meta.season?.id, user: me.id() }, 1).then((own) => ({
        ...result,
        me: app.store.pushPayload<StandingModel[]>(own).map(standing)[0] || null,
      }));
    }
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
