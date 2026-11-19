import Extend from 'flarum/common/extenders';
import PredictionModel from './models/PredictionModel';
import PickModel from './models/PickModel';
import StandingModel from './models/StandingModel';
import { CalledNotification, LockedNotification, MissedNotification } from './notifications';

/** The pages load in one lazy chunk, with the core layout pieces they build on. */
const page = (name: 'PredictionsPage' | 'PredictionPage') => () =>
  import('./components').then(async (ui) => {
    await ui.loadCore();
    return { default: ui[name] };
  });

export default [
  new Extend.Routes().add('predictions', '/predictions', page('PredictionsPage')).add('predictions.show', '/predictions/:id', page('PredictionPage')),

  new Extend.Store().add('predictions', PredictionModel).add('prediction-picks', PickModel).add('prediction-standings', StandingModel),

  new Extend.Notification()
    .add('predictionCalled', CalledNotification)
    .add('predictionMissed', MissedNotification)
    .add('predictionLocked', LockedNotification),
];
