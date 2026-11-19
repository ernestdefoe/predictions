<?php

namespace ErnestDefoe\Predictions\Api;

use ErnestDefoe\Predictions\Editor;
use ErnestDefoe\Predictions\Picks;
use ErnestDefoe\Predictions\Prediction;
use ErnestDefoe\Predictions\Settings;
use ErnestDefoe\Predictions\Settler;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context as BaseContext;
use Tobyz\JsonApiServer\Pagination\Pagination;

/**
 * The `predictions` type.
 *
 *   GET    /api/predictions                list (Listing: filter[status|id|discussion|mine|user])
 *   GET    /api/predictions/{id}           one
 *   POST   /api/predictions                open one (Editor::create)
 *   PATCH  /api/predictions/{id}           edit, or lockNow (Editor::update)
 *   DELETE /api/predictions/{id}           delete (Editor::delete)
 *   POST   /api/predictions/{id}/pick      {optionId} | {value}: pick or change a pick (Picks)
 *   DELETE /api/predictions/{id}/pick      take it back (Picks)
 *   POST   /api/predictions/{id}/settle    {optionId} | {value} | {void: true} (Settler)
 *
 * Visibility is scope(): the view permission, and a discussion's visibility for
 * one attached to a discussion. Every write is guarded here and checked again
 * under the prediction's RowLock where the answer can change in between.
 *
 * Input attributes are read whole by Editor (they depend on each other: the
 * kind decides whether choices or a unit apply), so their setters do nothing.
 *
 * @extends AbstractDatabaseResource<Prediction>
 */
class PredictionResource extends AbstractDatabaseResource
{
    /** Built on every API request: these construct without touching the database. */
    public function __construct(
        private readonly Cards $cards,
        private readonly Editor $editor,
        private readonly Picks $picks,
        private readonly Settler $settler,
        private readonly Listing $listing,
    ) {
    }

    public function type(): string
    {
        return 'predictions';
    }

    public function model(): string
    {
        return Prediction::class;
    }

    /** @param Builder<Prediction> $query */
    public function scope(Builder $query, BaseContext $context): void
    {
        /** @var Context $context */
        $query->visibleTo($context->getActor());
    }

    public function endpoints(): array
    {
        $include = ['user', 'discussion'];

        return [
            Endpoint\Index::make()
                ->defaultInclude($include)
                ->paginate(20, 50)
                ->query(fn (Builder $query, ?Pagination $pagination, Context $context, array $filters): Context => $this->listing->apply($query, $pagination, $context, $filters)),
            Endpoint\Show::make()->defaultInclude($include),
            Endpoint\Create::make()->authenticated()->can(Settings::CREATE)->defaultInclude($include),
            Endpoint\Update::make()->authenticated()->can('update')->defaultInclude($include),
            Endpoint\Delete::make()->authenticated()->can('delete'),
            Endpoint\Endpoint::make('pick')
                ->route('POST', '/{id}/pick')
                ->authenticated()
                ->can('pick')
                ->action(fn (Context $context): ?Prediction => $this->fresh($this->picks->pick(
                    $context->model,
                    $context->getActor(),
                    Input::id($context->body()['optionId'] ?? null),
                    Input::number($context->body()['value'] ?? null),
                ), $context)),
            Endpoint\Endpoint::make('unpick')
                ->route('DELETE', '/{id}/pick')
                ->authenticated()
                ->can('pick')
                ->action(fn (Context $context): ?Prediction => $this->fresh($this->picks->withdraw($context->model, $context->getActor()), $context)),
            Endpoint\Endpoint::make('settle')
                ->route('POST', '/{id}/settle')
                ->authenticated()
                ->can('settle')
                ->action(fn (Context $context): ?Prediction => $this->fresh($this->settler->settle(
                    $context->model,
                    $context->getActor(),
                    Input::id($context->body()['optionId'] ?? null),
                    Input::number($context->body()['value'] ?? null),
                    ! empty($context->body()['void']),
                ), $context)),
        ];
    }

    public function fields(): array
    {
        return [...$this->output(), ...$this->input(), ...$this->relationships()];
    }

    /** @param Context $context */
    public function create(object $model, BaseContext $context): object
    {
        return $this->editor->create($model, $context->getActor(), $this->attributes($context));
    }

    /** @param Context $context */
    public function update(object $model, BaseContext $context): object
    {
        return $this->editor->update($model, $context->getActor(), $this->attributes($context));
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        $this->editor->delete($model, $context->getActor());
    }

    /** @return list<Schema\Attribute> */
    private function output(): array
    {
        // "kind" is the presenter's "type": JSON:API reserves `type` for the resource type.
        $keys = ['kind', 'title', 'description', 'unit', 'tolerance', 'hidePicks', 'lockAt', 'status', 'picksVisible', 'picksCount',
            'options', 'stats', 'answer', 'correctOptionId', 'settledAt', 'scored', 'counted', 'minPickers', 'myPick', 'url', 'createdAt', 'can'];
        $writable = ['kind', 'title', 'description', 'unit', 'tolerance', 'hidePicks', 'lockAt', 'options'];

        return array_map(function (string $key) use ($writable) {
            $from = $key === 'kind' ? 'type' : $key;
            $field = Schema\Attribute::make($key)->get(fn (Prediction $prediction, Context $context) => $this->cards->value($prediction, $context, $from));

            return in_array($key, $writable, true) ? $field->writable()->set(fn () => null) : $field;
        }, $keys);
    }

    /** @return list<Schema\Attribute> */
    private function input(): array
    {
        return [
            Schema\Attribute::make('discussionId')->writable()->set(fn () => null)->get(fn (Prediction $prediction) => $prediction->discussion_id),
            Schema\Boolean::make('lockNow')->writableOnUpdate()->set(fn () => null)->get(fn () => null),
        ];
    }

    /** @return list<Schema\Relationship\ToOne> */
    private function relationships(): array
    {
        return [
            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            Schema\Relationship\ToOne::make('discussion')->type('discussions')->includable(),
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(Context $context): array
    {
        $attributes = (array) ($context->body()['data']['attributes'] ?? []);
        if (array_key_exists('kind', $attributes)) {
            $attributes['type'] = $attributes['kind'];
        }

        return $attributes;
    }

    /** The prediction read again through scope(), for the response. */
    private function fresh(Prediction $prediction, Context $context): ?Prediction
    {
        $found = $this->find((string) $prediction->id, $context);

        return $found instanceof Prediction ? $found : null;
    }
}
