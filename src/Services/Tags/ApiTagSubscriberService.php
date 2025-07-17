<?php

declare(strict_types=1);

namespace Sendportal\Base\Services\Tags;

use Exception;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Sendportal\Base\Models\Tag;
use Sendportal\Base\Repositories\TagTenantRepository;

class ApiTagSubscriberService
{
    /** @var TagTenantRepository */
    private $tags;

    public function __construct(TagTenantRepository $tags)
    {
        $this->tags = $tags;
    }

    /**
     * Add new subscribers to a tag.
     *
     * @throws Exception
     */
    public function store(int $workspaceId, int $tagId, Collection $subscriberIds): Collection
    {
        /** @var Tag $tag */
        $tag = $this->tags->find($workspaceId, $tagId);

        $existingSubscribers = $tag->subscribers()
            ->whereIn('subscriber_id', $subscriberIds)
            ->pluck('subscriber_id')
            ->toBase();

        $subscribersToStore = $subscriberIds->diff($existingSubscribers);

        $tag->subscribers()->attach($subscribersToStore);

        return $tag->subscribers()->whereIn('subscriber_id', $subscriberIds)->get()->toBase();
    }

    /**
     * Sync subscribers on a tag.
     *
     * @throws Exception
     */
    public function update(int $workspaceId, int $tagId, Collection $subscriberIds): EloquentCollection
    {
        $tag = $this->tags->find($workspaceId, $tagId);

        $tag->subscribers()->sync($subscriberIds);

        $tag->load('subscribers');

        return $tag->subscribers;
    }

    /**
     * Remove subscribers from a tag.
     *
     * @throws Exception
     */
    public function destroy(int $workspaceId, int $tagId, Collection $subscriberIds): EloquentCollection
    {
        $tag = $this->tags->find($workspaceId, $tagId);

        $tag->subscribers()->detach($subscriberIds);

        return $tag->subscribers;
    }
}
