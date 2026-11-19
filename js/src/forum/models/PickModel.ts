import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';

/** The `prediction-picks` type: one member's pick on a prediction (who picked what). */
export default class PickModel extends Model {
  optionId = Model.attribute<number | null>('optionId');
  value = Model.attribute<number | null>('value');
  hit = Model.attribute<boolean | null>('hit');
  points = Model.attribute<number | null>('points');
  user = Model.hasOne<User>('user');
}
