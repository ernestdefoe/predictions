<?php

namespace ErnestDefoe\Predictions\Api;

use Carbon\Carbon;
use ErnestDefoe\Predictions\Option;
use ErnestDefoe\Predictions\Pick;
use ErnestDefoe\Predictions\Picks;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Refusal;
use ErnestDefoe\Predictions\Settings;
use Flarum\Discussion\Discussion;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST  /api/predictions        {type, title, description, options[], unit, tolerance, lockAt, hidePicks, discussionId}
 * PATCH /api/predictions/{id}   the same fields, any subset, plus lockNow: true
 *
 * The creator edits while it's open, a moderator until it's settled. The
 * choices (and the type) only change while nobody else has picked.
 */
class SaveController extends Handler
{
    public const DAILY = 20;

    public function __construct(
        private readonly Presenter $presenter,
        private readonly Picks $picks,
    ) {
    }

    protected function respond(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $body = self::body($request);

        if ($request->getMethod() === 'PATCH') {
            $prediction = Find::visible($request, $actor);
            if (! $prediction->canEdit($actor)) {
                self::deny();
            }
            $this->update($prediction, $body, $actor);

            return new JsonResponse(['data' => $this->presenter->single($prediction->fresh() ?? $prediction, $actor)]);
        }

        $actor->assertCan(Settings::CREATE);
        $actor->assertCan(Settings::VIEW);
        if (! $actor->hasPermission(Settings::MODERATE)
            && Prediction::query()->where('user_id', $actor->id)->where('created_at', '>', Carbon::now()->subDay())->count() >= self::DAILY) {
            throw new Refusal('predictions_slow_down', 429);
        }

        $prediction = new Prediction();
        $prediction->user_id = $actor->id;
        $prediction->type = Input::type($body['type'] ?? null);
        $prediction->title = Input::title($body['title'] ?? null);
        $prediction->description = Input::description($body['description'] ?? null);
        $prediction->lock_at = Input::lockAt($body['lockAt'] ?? null);
        $prediction->hide_picks = (bool) ($body['hidePicks'] ?? false);
        $prediction->discussion_id = $this->discussion($body['discussionId'] ?? null, $actor);
        $labels = $this->labels($prediction, $body);
        if ($prediction->type === Prediction::NUMBER) {
            $prediction->unit = Input::unit($body['unit'] ?? null);
            $prediction->tolerance = Input::tolerance($body['tolerance'] ?? null);
        }

        $prediction->getConnection()->transaction(function () use ($prediction, $labels) {
            $prediction->save();
            $this->replaceOptions($prediction, $labels);
        });

        return new JsonResponse(['data' => $this->presenter->single($prediction->fresh() ?? $prediction, $actor)], 201);
    }

    /** @param array<string, mixed> $body */
    private function update(Prediction $prediction, array $body, User $actor): void
    {
        $prediction->getConnection()->transaction(function () use ($prediction, $body, $actor) {
            // Re-read and count under the lock: a pick or a settle may have landed since the check above.
            $row = Prediction::query()->whereKey($prediction->id)->lockForUpdate()->firstOrFail();
            if (! $row->canEdit($actor)) {
                self::deny();
            }
            $others = Pick::query()->where('prediction_id', $row->id)->where('user_id', '<>', $actor->id)->count();

            // Once others have picked, the question they answered (and whether picks are hidden)
            // only changes by a moderator's hand.
            $frozen = ! $row->canEditWording($actor, $others);
            if (array_key_exists('title', $body)) {
                $title = Input::title($body['title']);
                if ($frozen && $title !== $row->title) {
                    throw new Refusal('predictions_frozen');
                }
                $row->title = $title;
            }
            if (array_key_exists('description', $body)) {
                $row->description = Input::description($body['description']);
            }
            if (array_key_exists('hidePicks', $body) && ! $row->isLocked()) {
                if ($frozen && (bool) $body['hidePicks'] !== $row->hide_picks) {
                    throw new Refusal('predictions_frozen');
                }
                $row->hide_picks = (bool) $body['hidePicks'];
            }
            if (! empty($body['lockNow'])) {
                $row->lock_at = Carbon::now();
            } elseif (array_key_exists('lockAt', $body) && ! $row->isLocked()) {
                $lockAt = Input::lockAt($body['lockAt']);
                // Once others have picked, only a moderator can push the lock later: a creator
                // could otherwise keep picks open after the game started and pick knowing the score.
                if ($others > 0 && $lockAt->gt($row->lock_at) && ! $row->canModerate($actor)) {
                    throw new Refusal('predictions_lock_later');
                }
                $row->lock_at = $lockAt;
            }
            if (array_key_exists('discussionId', $body)) {
                $row->discussion_id = $this->discussion($body['discussionId'], $actor);
            }

            $labels = null;
            if (array_key_exists('options', $body) || array_key_exists('type', $body) || array_key_exists('unit', $body) || array_key_exists('tolerance', $body)) {
                if (! $row->canEditOptions($actor, $others)) {
                    throw new Refusal('predictions_has_picks');
                }
                $type = array_key_exists('type', $body) ? Input::type($body['type']) : $row->type;
                if ($type !== $row->type || array_key_exists('options', $body)) {
                    $row->type = $type;
                    $labels = $this->labels($row, $body + ['options' => $row->options()->pluck('label')->all()]);
                }
                $row->unit = $row->type === Prediction::NUMBER ? Input::unit($body['unit'] ?? $row->unit) : null;
                $row->tolerance = $row->type === Prediction::NUMBER ? Input::tolerance(array_key_exists('tolerance', $body) ? $body['tolerance'] : $row->tolerance) : null;
            }

            $row->save();

            if ($labels !== null) {
                $this->replaceOptions($row, $labels);
                // The actor's own pick (the only one there can be) pointed at the old choices.
                Pick::query()->where('prediction_id', $row->id)->delete();
                $this->picks->recount($row);
            }
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return list<string>
     */
    private function labels(Prediction $prediction, array $body): array
    {
        return match ($prediction->type) {
            Prediction::YESNO => ['Yes', 'No'],
            Prediction::NUMBER => [],
            default => Input::options($body['options'] ?? null),
        };
    }

    /** @param list<string> $labels */
    private function replaceOptions(Prediction $prediction, array $labels): void
    {
        Option::query()->where('prediction_id', $prediction->id)->delete();
        foreach ($labels as $i => $label) {
            Option::query()->insert(['prediction_id' => $prediction->id, 'label' => $label, 'position' => $i, 'picks_count' => 0]);
        }
    }

    /**
     * Attach to a discussion its author (or a moderator) can see and reply to.
     * Null detaches.
     */
    private function discussion(mixed $value, User $actor): ?int
    {
        $id = Input::id($value);
        if ($id === null) {
            return null;
        }

        $discussion = Discussion::query()->whereVisibleTo($actor)->find($id);
        if (! $discussion || ! $actor->can('reply', $discussion)
            || ($discussion->user_id !== $actor->id && ! $actor->hasPermission(Settings::MODERATE))) {
            throw new Refusal('predictions_bad_discussion');
        }

        return $discussion->id;
    }
}
