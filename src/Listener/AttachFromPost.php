<?php

namespace ErnestDefoe\Predictions\Listener;

use ErnestDefoe\Predictions\Prediction;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Event\Posted;

/**
 * A prediction made from the composer of a NEW discussion belongs to that
 * discussion: when the first post links one of the author's own standalone
 * predictions, it is attached. Others' predictions are only embedded.
 */
class AttachFromPost
{
    public function __construct(private readonly UrlGenerator $url)
    {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        if ($post->number !== 1 || ! $post->discussion_id) {
            return;
        }

        $base = preg_quote(rtrim($this->url->to('forum')->base(), '/'), '#');
        if (! preg_match_all('#'.$base.'/predictions/(\d+)#', (string) $post->getAttributes()['content'], $matches)) {
            return;
        }

        Prediction::query()
            ->whereIn('id', array_slice(array_map('intval', $matches[1]), 0, 20))
            ->where('user_id', $post->user_id)
            ->whereNull('discussion_id')
            ->update(['discussion_id' => $post->discussion_id]);
    }
}
