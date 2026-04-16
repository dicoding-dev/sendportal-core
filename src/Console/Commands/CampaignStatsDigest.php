<?php

declare(strict_types=1);

namespace Sendportal\Base\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStat;
use Sendportal\Base\Models\CampaignStatus;

class CampaignStatsDigest extends Command
{
    protected $signature = 'sp:stats:digest {--freeze-after=}';

    protected $description = 'Recalculate opened/clicked/bounced stats for dirty campaigns and freeze old ones';

    public function handle(): int
    {
        $freezeAfterDays = (int) ($this->option('freeze-after')
            ?? config('sendportal.stats.freeze_after_days', 45));

        $dirtyIds = $this->consumeDirtySet();

        if ($dirtyIds->isNotEmpty()) {
            $unfrozenIds = CampaignStat::whereIn('campaign_id', $dirtyIds)
                ->whereNull('stats_frozen_at')
                ->pluck('campaign_id');

            if ($unfrozenIds->isNotEmpty()) {
                $this->recalculate($unfrozenIds);
                $this->info("Recalculated stats for {$unfrozenIds->count()} campaigns.");
            }
        }

        $this->freezeOldCampaigns(now()->subDays($freezeAfterDays), $freezeAfterDays);

        return self::SUCCESS;
    }

    private function consumeDirtySet(): \Illuminate\Support\Collection
    {
        $tempKey = 'sp:stats:dirty:processing';

        try {
            Redis::rename('sp:stats:dirty', $tempKey);
        } catch (\Exception $e) {
            return collect();
        }

        $ids = collect(Redis::smembers($tempKey))->map(fn ($id) => (int) $id);
        Redis::del($tempKey);

        return $ids;
    }

    private function recalculate(\Illuminate\Support\Collection $campaignIds): void
    {
        foreach ($campaignIds->chunk(100) as $batch) {
            $counts = DB::table('sendportal_messages')
                ->select([
                    'source_id as campaign_id',
                    DB::raw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened'),
                    DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked'),
                    DB::raw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced'),
                ])
                ->where('source_type', Campaign::class)
                ->whereIn('source_id', $batch)
                ->groupBy('source_id')
                ->get();

            foreach ($counts as $row) {
                CampaignStat::where('campaign_id', $row->campaign_id)
                    ->update([
                        'opened' => $row->opened,
                        'clicked' => $row->clicked,
                        'bounced' => $row->bounced,
                    ]);
            }
        }
    }

    private function freezeOldCampaigns(Carbon $cutoffDate, int $days): void
    {
        $frozenCount = CampaignStat::whereNull('stats_frozen_at')
            ->whereIn('campaign_id', function ($query) use ($cutoffDate) {
                $query->select('id')
                    ->from('sendportal_campaigns')
                    ->where('status_id', CampaignStatus::STATUS_SENT)
                    ->where('updated_at', '<', $cutoffDate);
            })
            ->update(['stats_frozen_at' => now()]);

        if ($frozenCount > 0) {
            $this->info("Froze stats for {$frozenCount} campaigns older than {$days} days.");
        }
    }
}
