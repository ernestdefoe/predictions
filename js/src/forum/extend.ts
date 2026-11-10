import Extend from 'flarum/common/extenders';
import Model from 'flarum/common/Model';

/** The `predictions` type, so a notification's subject can be stored. Only its title is read. */
export class PredictionModel extends Model {
  title = Model.attribute<string>('title');
  status = Model.attribute<string>('status');
}

export default [new Extend.Store().add('predictions', PredictionModel)];
