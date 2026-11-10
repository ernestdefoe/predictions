# Predictions: working rules

- **Commit straight to `main` and push. Never open a pull request on this repo.** Commit subjects are conventional (`feat:`, `fix:`); the release drafter bumps from the subject.
- `js/dist` is committed and is what forums load. After any change under `js/src`, run `npm run build` in `js/` (from a fresh `npm ci`) and commit the bundle; CI fails if it is stale.
- Publishing a release is Ernest's click. Nothing is announced until he says so. Every change goes to the Command Center session for review before release.

## Design rules that exist for a reason

- **Picks are judged by `lock_at`, under a row lock** (`Picks::lockRow`: SELECT … FOR UPDATE, then re-check). Never accept a pick based on a copy of the prediction read before the transaction, and never depend on `locked_at` (the scheduler's stamp, used only for the "time to settle" alert).
- **Settling never scores in the request.** `Settler` writes the answer under the row lock, clears `scored_at` and queues `Job\ScorePrediction`. `Scorer` runs only while `scored_at` is null, under the row lock, and rebuilds every pick result and the touched members' season rows (`Scores::rebuild`) from scratch. `predictions:tick` (every minute) scores anything left unscored. Every step must stay idempotent.
- 🚨 **Whoever settles a prediction never scores from it** (`Scorer` nulls their pick, `Scores::rebuild` skips it): otherwise a member opens a yes/no, picks, settles it their way and farms points. Once others have picked, only a moderator can move the lock LATER (a creator could reopen picks after kickoff).
- Points are written with one UPDATE per distinct result, never one per pick. Totals are recounted, never incremented.
- The leaderboard reads `prediction_scores` only (one query + users). Never sum picks at read time.
- Notification blueprints carry **no data**, so `sync()` matches the earlier alert and a correction or void takes it back. Their subject (`Prediction`) has the `predictions` API resource and the `predictions` store model: without either, notification lists break.
- `Presenter::many` loads a page of cards in a fixed number of queries (eager loads + one query for the viewer's picks + one for "closest" faces). Keep it that way.
- Hidden picks: option counts, number stats and who-picked are left out on the server while `hide_picks` and the lock hasn't passed (`Prediction::picksVisible`). Someone else's picks list (`filter[user]`) only covers predictions whose picks are already visible.
- `predictionIds` on a discussion is only on the discussion Show endpoint; `predictionRecord` on a user only on the user Show endpoint. Never add per-item work to list endpoints.
- Frontend: forum.js only wires things up; everything else is ONE lazy chunk (`components/index.ts`). Two chunks sharing the card make webpack emit a shared chunk `jsDirectory` doesn't publish.
- Cards inside posts are separate Mithril roots (`m.mount`, `components/mount.tsx`): a post's subtree retainer would otherwise never redraw them.
- Mithril has no function components: every component is a class.
- Composer chip: priority 1 in DiscussionComposer, -5 in ReplyComposer (the reply's title stays first), spaced to match FoF Polls' "Add Poll".
- Avatars: Flarum 2 sizes them with `--size`, and our fake user's `color()` needs the `#`.
- FoF Badges is touched only in `Award/BadgeAward.php`, constructed only when `fof-badges` is enabled. The Oracle badge has no trigger, so FoF's recalculation never revokes it.

## Testing

- `composer test:setup && composer test` (integration; set `DB_DRIVER=mysql` and a `DB_PREFIX` to test prefixes), `composer analyse:phpstan`.
- `cd js && npm ci && npm run build && npm run format-check`.
- Local forum: ~/Herd/preddev (https://preddev.test, prefix `pd_`); screenshots: ~/github/scopes/predictions-shots.
