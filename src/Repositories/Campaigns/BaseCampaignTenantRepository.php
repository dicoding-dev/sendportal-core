<?php

declare(strict_types=1);

namespace Sendportal\Base\Repositories\Campaigns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStat;
use Sendportal\Base\Models\CampaignStatus;
use Sendportal\Base\Models\Tag;
use Sendportal\Base\Repositories\BaseTenantRepository;
use Sendportal\Base\Traits\SecondsToHms;

abstract class BaseCampaignTenantRepository extends BaseTenantRepository implements CampaignTenantRepositoryInterface
{
    use SecondsToHms;

    /** @var string */
    protected $modelName = Campaign::class;

    /**
     * {@inheritDoc}
     */
    public function completedCampaigns(int $workspaceId, int $limit = 10, array $relations = []): EloquentCollection
    {
        return $this->getQueryBuilder($workspaceId)
            ->where('status_id', CampaignStatus::STATUS_SENT)
            ->with($relations)
            ->take($limit)
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function getCounts(Collection $campaignIds, int $workspaceId): array
    {
        $stats = CampaignStat::whereIn('campaign_id', $campaignIds)
            ->get()
            ->keyBy('campaign_id');

        // Ensure every requested campaign has a stats entry
        foreach ($campaignIds as $id) {
            if (! $stats->has($id)) {
                $stats[$id] = new CampaignStat([
                    'campaign_id' => $id,
                    'total' => 0,
                    'sent' => 0,
                    'opened' => 0,
                    'clicked' => 0,
                    'bounced' => 0,
                    'pending' => 0,
                ]);
            }
        }

        return $stats->sortKeys()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function cancelCampaign(Campaign $campaign): bool
    {
        $this->deleteDraftMessages($campaign);

        return $campaign->update([
            'status_id' => CampaignStatus::STATUS_CANCELLED,
        ]);
    }

    private function deleteDraftMessages(Campaign $campaign): void
    {
        if (! $campaign->save_as_draft) {
            return;
        }

        DB::transaction(function () use ($campaign) {
            $pendingCount = $campaign->messages()->whereNull('sent_at')->count();
            $campaign->messages()->whereNull('sent_at')->delete();

            if ($pendingCount > 0) {
                CampaignStat::where('campaign_id', $campaign->id)
                    ->update([
                        'total' => DB::raw("total - {$pendingCount}"),
                        'pending' => DB::raw("pending - {$pendingCount}"),
                    ]);
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $instance, array $filters = []): void
    {
        $this->applySentFilter($instance, $filters);
        $this->applyNameFilter($instance, $filters);
    }

    /**
     * Filter by sent status.
     */
    protected function applySentFilter(Builder $instance, array $filters = []): void
    {
        if (Arr::get($filters, 'draft')) {
            $draftStatuses = [
                CampaignStatus::STATUS_DRAFT,
                CampaignStatus::STATUS_QUEUED,
                CampaignStatus::STATUS_SENDING,
            ];

            $instance->whereIn('status_id', $draftStatuses);
        } elseif (Arr::get($filters, 'sent')) {
            $sentStatuses = [
                CampaignStatus::STATUS_SENT,
                CampaignStatus::STATUS_CANCELLED,
            ];

            $instance->whereIn('status_id', $sentStatuses);
        }
    }

    /**
     * Filter by campaign name.
     */
    protected function applyNameFilter(Builder $instance, array $filters = []): void
    {
        if (Arr::get($filters, 'name')) {
            $instance->where($instance->getModel()->getTable() . '.name', Arr::get($filters, 'name'));
        }
    }

    public function destroy($workspaceId, $id)
    {
        /** @var Campaign $campaign */
        $campaign = $this->find($workspaceId, $id);
        /** @var Tag $tag */
        $tag = $campaign->tags()->where('name', '=', 'Campaign: '.$campaign->name)->first();

        /** Detach subscribers and delete tag specific to this campaign */
        if ($tag) {
            $tag->subscribers()->detach();
            $tag->delete();
        }

        /** Detach all other tags used by this campaign */
        $campaign->tags()->detach();

        return parent::destroy($workspaceId, $id);
    }
}
