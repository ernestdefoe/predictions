/*
 * The one lazy chunk: the card, the pages and the forms. forum.js only wires
 * things up; everything here loads the first time a page, an embed or a
 * form needs it. One entry on purpose: two chunks sharing the card would make
 * webpack emit a numbered shared chunk that jsDirectory doesn't publish.
 */
export { default as PredictionCard } from './PredictionCard';
export { default as PredictionsPage, loadCore } from './PredictionsPage';
export { default as PredictionPage } from './PredictionPage';
export { default as PredictionModal } from './PredictionModal';
export { default as SettleModal } from './SettleModal';
export { mountCards } from './mount';
