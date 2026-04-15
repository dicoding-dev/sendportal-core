<?php

declare(strict_types=1);

namespace Sendportal\Base\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignStat extends Model
{
    protected $table = 'sendportal_campaign_stats';

    protected $primaryKey = 'campaign_id';

    public $incrementing = false;

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
}
