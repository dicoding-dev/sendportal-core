<?php

declare(strict_types=1);

namespace Sendportal\Base\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Sendportal\Base\Models\Campaign;
use Sendportal\Base\Models\CampaignStat;

class BackfillCampaignStats extends Command
{
    protected $signature = 'sp:stats:backfill {--chunk=100}';

    protected $description = 'Backfill campaign stats from the messages table';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');

        $campaignIds = DB::table('sendportal_messages')
            ->where('source_type', Campaign::class)
            ->distinct()
            ->pluck('source_id');

        $this->info("Found {$campaignIds->count()} campaigns to backfill.");

        $campaigns = DB::table('sendportal_campaigns')
            ->whereIn('id', $campaignIds)
            ->pluck('created_at', 'id');

        $bar = $this->output->createProgressBar($campaignIds->count());

        foreach ($campaignIds->chunk($chunkSize) as $batch) {
            $counts = DB::table('sendportal_messages')
                ->select([
                    'source_id as campaign_id',
                    DB::raw('COUNT(*) as total'),
                    DB::raw('SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent'),
                    DB::raw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened'),
                    DB::raw('SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked'),
                    DB::raw('SUM(CASE WHEN bounced_at IS NOT NULL THEN 1 ELSE 0 END) as bounced'),
                    DB::raw('SUM(CASE WHEN sent_at IS NULL THEN 1 ELSE 0 END) as pending'),
                ])
                ->where('source_type', Campaign::class)
                ->whereIn('source_id', $batch)
                ->groupBy('source_id')
                ->get();

            foreach ($counts as $row) {
                CampaignStat::upsertStats(
                    $row->campaign_id,
                    (array) $row,
                    $campaigns[$row->campaign_id] ?? null
                );
            }

            $bar->advance($batch->count());
        }

        $bar->finish();
        $this->newLine();
        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
