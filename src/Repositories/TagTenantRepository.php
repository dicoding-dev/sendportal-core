<?php

namespace Sendportal\Base\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Sendportal\Base\Models\Tag;

class TagTenantRepository extends BaseTenantRepository
{
    /**
     * @var string
     */
    protected $modelName = Tag::class;

    /**
     * {@inheritDoc}
     */
    public function update($workspaceId, $id, array $data)
    {
        $instance = $this->find($workspaceId, $id);

        $this->executeSave($workspaceId, $instance, $data);

        return $instance;
    }

    /**
     * Sync subscribers
     *
     * @param Tag $tag
     * @param array $subscribers
     * @return array
     */
    public function syncSubscribers(Tag $tag, array $subscribers = [])
    {
        return $tag->subscribers()->sync($subscribers);
    }

    /**
     * {@inheritDoc}
     */
    public function destroy($workspaceId, $id): bool
    {
        $instance = $this->find($workspaceId, $id);

        $instance->subscribers()->detach();
        $instance->campaigns()->detach();

        return $instance->delete();
    }

    /**
     * @inheritDoc
     */
    protected function applyFilters(Builder $instance, array $filters = []): void
    {
        $this->applyNameFilter($instance, $filters);
    }

    /**
     * Filter by name or email.
     */
    protected function applyNameFilter(Builder $instance, array $filters): void
    {
        if ($name = Arr::get($filters, 'name')) {
            $instance->where($instance->getModel()->getTable() . '.name', $name);
        }
    }
}
