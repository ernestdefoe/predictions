<?php

namespace ErnestDefoe\Predictions\Http;

use ErnestDefoe\Predictions\Prediction;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The /predictions pages as the server sends them: a real title, canonical
 * URL and description, so a shared link reads well. One query on a single
 * prediction's page, none on the list.
 */
class PageContent
{
    public function __construct(
        private readonly UrlGenerator $url,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): Document
    {
        $id = (int) Arr::get($request->getQueryParams(), 'id');
        $prediction = $id ? Prediction::query()->visibleTo(RequestUtil::getActor($request))->find($id) : null;

        if ($prediction) {
            $document->title = $prediction->title;
            $document->canonicalUrl = $this->url->to('forum')->route('predictions.show', ['id' => $prediction->id]);
            $document->meta['description'] = mb_substr($prediction->description ?: (string) $this->translator->trans('ernestdefoe-predictions.forum.page.description'), 0, 200);
        } else {
            $document->title = (string) $this->translator->trans('ernestdefoe-predictions.forum.page.title');
            $document->canonicalUrl = $this->url->to('forum')->route('predictions');
            $document->meta['description'] = (string) $this->translator->trans('ernestdefoe-predictions.forum.page.description');
        }

        return $document;
    }
}
