import Model from 'flarum/common/Model';
import type User from 'flarum/common/models/User';
import type Discussion from 'flarum/common/models/Discussion';

/**
 * The `predictions` type. Cards read predictions as plain objects (data.ts
 * `plain()`); the store keeps the JSON:API records and their relationships,
 * and notifications read the title of their subject from here.
 */
export default class PredictionModel extends Model {
  title = Model.attribute<string>('title');
  status = Model.attribute<string>('status');
  user = Model.hasOne<User>('user');
  discussion = Model.hasOne<Discussion>('discussion');
}
