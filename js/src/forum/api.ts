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
  /** Null until scored; false when too few picked for it to count on the leaderboard. */
  counted: boolean | null;
  minPickers: number;
  myPick: MyPick | null;
  user: Person | null;
  discussion: { id: number; title: string; slug: string } | null;
  url: string;
  createdAt: string;
  can: { pick: boolean; edit: boolean; editOptions: boolean; editWording: boolean; settle: boolean; void: boolean; delete: boolean };
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

export function predictionUrl(id: number): string {
  return app.forum.attribute<string>('baseUrl') + '/predictions/' + id;
}

export function percent(value: number): string {
  return new Intl.NumberFormat(app.translator.getLocale() || undefined, { style: 'percent', maximumFractionDigits: 0 }).format(value);
}

/** A yes/no prediction's two choices, in the reader's language (they're stored as "Yes" and "No"). */
export function optionLabel(prediction: Prediction, option: Option, index: number): string {
  return prediction.type === 'yesno' ? tx(index === 0 ? 'card.yes' : 'card.no') : option.label;
}

export function openForm(attrs: { prediction?: Prediction; discussionId?: number; onsave?: (p: Prediction) => void } = {}): void {
  app.modal.show(() => import('./components').then((ui) => ({ default: ui.PredictionModal })), attrs);
}
