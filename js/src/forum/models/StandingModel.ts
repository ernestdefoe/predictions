import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

/** The `prediction-standings` type: one member's row on the leaderboard (a season's, or all time). */
export default class StandingModel extends Model {
  rank = Model.attribute<number>('rank');
  points = Model.attribute<number>('points');
  hits = Model.attribute<number>('hits');
  settled = Model.attribute<number>('settled');
  hitRate = Model.attribute<number>('hitRate');
  streak = Model.attribute<number | null>('streak');
  bestStreak = Model.attribute<number>('bestStreak');
  oracle = Model.attribute<boolean>('oracle');
  user = Model.hasOne<User>('user');
}
