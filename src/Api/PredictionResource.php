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
 * Input fields validate and set their values like any resource; Editor then
 * checks what depends on the whole row under its lock (see inputs()).
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
        $query->whereVisibleTo($context->getActor());
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
        return [...$this->inputs(), ...$this->computed(), ...$this->relationships()];
    }

    /** @param Context $context */
    public function create(object $model, BaseContext $context): object
    {
        return $this->editor->create($model, $context->getActor());
    }

    /** @param Context $context */
    public function update(object $model, BaseContext $context): object
    {
        return $this->editor->update($model, $context->getActor());
    }

    /** @param Context $context */
    public function delete(object $model, BaseContext $context): void
    {
        $this->editor->delete($model, $context->getActor());
    }

    /**
     * What a member writes. Each field validates and sets its value; Editor then
     * checks, under the row lock, what depends on the rest of the row (whether
     * others have picked, whether the kind needs choices, the lock time's range).
     *
     * @return list<Schema\Attribute>
     */
    private function inputs(): array
    {
        return [
            // JSON:API reserves `type` for the resource type, so the kind travels as `kind`.
            Schema\Str::make('kind')->property('type')->requiredOnCreate()->writable()->in(Prediction::TYPES),
            Schema\Str::make('title')->requiredOnCreate()->writable()->minLength(3)->maxLength(150)
                ->set(fn (Prediction $prediction, string $value) => $prediction->title = Input::title($value)),
            Schema\Str::make('description')->nullable()->writable()->maxLength(1000)
                ->set(fn (Prediction $prediction, ?string $value) => $prediction->description = Input::description($value)),
            Schema\Str::make('unit')->nullable()->writable()->maxLength(24)
                ->set(fn (Prediction $prediction, ?string $value) => $prediction->unit = Input::unit($value)),
            Schema\Number::make('tolerance')->nullable()->writable()->min(0)->max(1000000000)
                ->set(fn (Prediction $prediction, ?float $value) => $prediction->tolerance = $value ?: null),
            Schema\Boolean::make('hidePicks')->property('hide_picks')->writable(),
            Schema\DateTime::make('lockAt')->property('lock_at')->requiredOnCreate()->writable(),
            Schema\Arr::make('options')->nullable()->writable()
                ->set(fn (Prediction $prediction, ?array $labels) => $prediction->pendingLabels = $labels === null ? null : Input::options($labels))
                ->get(fn (Prediction $prediction, Context $context) => $this->cards->value($prediction, $context, 'options')),
            Schema\Boolean::make('lockNow')->writableOnUpdate()->visible(false)
                ->set(fn (Prediction $prediction, bool $now) => $prediction->lockNow = $now),
        ];
    }

    /**
     * What depends on the viewer or on other rows, laid out a page at a time by Cards.
     *
     * @return list<Schema\Attribute>
     */
    private function computed(): array
    {
        $keys = ['status', 'picksVisible', 'picksCount', 'stats', 'answer', 'correctOptionId', 'settledAt', 'scored', 'counted',
            'minPickers', 'myPick', 'url', 'createdAt', 'can'];

        return array_map(
            fn (string $key) => Schema\Attribute::make($key)->get(fn (Prediction $prediction, Context $context) => $this->cards->value($prediction, $context, $key)),
            $keys,
        );
    }

    /** @return list<Schema\Relationship\ToOne> */
    private function relationships(): array
    {
        return [
            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
            // Attached when it's opened, never moved after: Editor checks the member may attach it.
            Schema\Relationship\ToOne::make('discussion')->type('discussions')->includable()->writableOnCreate()->nullable(),
        ];
    }

    /** The prediction read again through scope(), for the response. */
    private function fresh(Prediction $prediction, Context $context): ?Prediction
    {
        $found = $this->find((string) $prediction->id, $context);

        return $found instanceof Prediction ? $found : null;
    }
}
