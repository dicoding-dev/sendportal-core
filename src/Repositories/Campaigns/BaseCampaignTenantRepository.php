<?php

declare(strict_types=1);

namespace Sendportal\Base\Repositories\Campaigns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Models\Campaign;
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
        return cache()->remember(
            'sendportal_campaigns_counts:v2:'.$campaignIds->implode(','),
            60,
            function () use ($campaignIds) {
                $counts = DB::table('sendportal_campaigns')
                    ->leftJoin('sendportal_messages', function ($join) {
                        $join->on('sendportal_messages.source_id', '=', 'sendportal_campaigns.id')
                            ->where('sendportal_messages.source_type', Campaign::class);
                    })
                    ->whereIn('sendportal_campaigns.id', $campaignIds)
                    ->select('sendportal_campaigns.id as campaign_id')
                    ->selectRaw('COUNT(sendportal_messages.id) as total')
                    ->selectRaw('SUM(CASE WHEN sendportal_messages.opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened')
                    ->selectRaw('SUM(CASE WHEN sendportal_messages.clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked')
                    ->selectRaw('SUM(CASE WHEN sendportal_messages.sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent')
                    ->selectRaw('SUM(CASE WHEN sendportal_messages.bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced')
                    ->selectRaw('SUM(CASE WHEN sendportal_messages.sent_at IS NULL THEN 1 ELSE 0 END) as pending')
                    ->groupBy('sendportal_campaigns.id')
                    ->get();

                return $counts->flatten()
                    ->keyBy('campaign_id')
                    ->sortKeys()
                    ->toArray();
            }
        );
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

        $campaign->messages()->whereNull('sent_at')->delete();
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
