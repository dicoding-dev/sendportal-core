<?php

declare(strict_types=1);

namespace Sendportal\Base\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignStat extends Model
{
    protected $table = 'sendportal_campaign_stats';

    protected $primaryKey = 'campaign_id';

    public $incrementing = false;

    public $timestamps = true;

    protected $fillable = [
        'campaign_id',
        'total',
        'sent',
        'opened',
        'clicked',
        'bounced',
        'pending',
        'stats_frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'stats_frozen_at' => 'datetime',
        ];
    }

    /**
     * Upsert stats using query builder to bypass Eloquent timestamp handling.
     */
    public static function upsertStats(int $campaignId, array $stats, ?string $createdAt = null): void
    {
        static::getConnectionResolver()
            ->connection()
            ->table('sendportal_campaign_stats')
            ->upsert(
                [
                    'campaign_id' => $campaignId,
                    'total' => $stats['total'] ?? 0,
                    'sent' => $stats['sent'] ?? 0,
                    'opened' => $stats['opened'] ?? 0,
                    'clicked' => $stats['clicked'] ?? 0,
                    'bounced' => $stats['bounced'] ?? 0,
                    'pending' => $stats['pending'] ?? 0,
                    'created_at' => $createdAt,
                    'updated_at' => now(),
                ],
                ['campaign_id'],
                ['total', 'sent', 'opened', 'clicked', 'bounced', 'pending', 'updated_at']
            );
    }
}
