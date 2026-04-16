# Materialized Campaign Stats Table

**Status: ✅ Implemented** (`feat/campaign-stats-table`)

## Problem

`getCounts()` in `BaseCampaignTenantRepository` LEFT JOINs `sendportal_messages` (34M rows, 50 HASH partitions) onto campaign IDs and aggregates 5 conditional counts. With 25 campaigns per page, the query scans millions of rows even with partition pruning because there is no composite index on `(source_id, source_type)` and the aggregation columns (`opened_at`, `clicked_at`, `sent_at`, `bounced_at`) require row lookups from the clustered index.

The 60-second cache mitigates but doesn't eliminate the problem — first hit after expiry pays full cost, and different campaign ID sets produce different cache keys (always miss).

## Solution

Pre-compute per-campaign message counts into a `sendportal_campaign_stats` table. Update `total`/`pending`/`sent` in real-time during the dispatch pipeline. Recalculate `opened`/`clicked`/`bounced` via a scheduled digest command every N minutes, driven by a Redis dirty-set. Freeze stats for campaigns older than N days. Replace the aggregate JOIN query with a simple `SELECT * WHERE campaign_id IN (...)`.

## Table Schema

**File**: `database/migrations/2026_04_15_000001_create_campaign_stats_table.php`

```sql
CREATE TABLE sendportal_campaign_stats (
    campaign_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    total           INT UNSIGNED NOT NULL DEFAULT 0,
    sent            INT UNSIGNED NOT NULL DEFAULT 0,
    opened          INT UNSIGNED NOT NULL DEFAULT 0,
    clicked         INT UNSIGNED NOT NULL DEFAULT 0,
    bounced         INT UNSIGNED NOT NULL DEFAULT 0,
    pending         INT UNSIGNED NOT NULL DEFAULT 0,
    stats_frozen_at TIMESTAMP NULL DEFAULT NULL,
    updated_at      TIMESTAMP NULL
);
```

## Configuration

**File**: `config/config.php` — add under existing keys:

```php
'stats' => [
    'digest_interval' => env('SENDPORTAL_STATS_DIGEST_INTERVAL', 15),
    'freeze_after_days' => env('SENDPORTAL_STATS_FREEZE_AFTER_DAYS', 45),
],
```

| Env variable | Default | Description |
|---|---|---|
| `SENDPORTAL_STATS_DIGEST_INTERVAL` | `15` | Minutes between digest runs |
| `SENDPORTAL_STATS_FREEZE_AFTER_DAYS` | `45` | Days after campaign completion to freeze stats |

## Model

**File**: `src/Models/CampaignStat.php`

```php
class CampaignStat extends Model
{
    protected $table = 'sendportal_campaign_stats';
    protected $primaryKey = 'campaign_id';
    public $incrementing = false;
    protected $fillable = [
        'campaign_id', 'total', 'sent', 'opened', 'clicked', 'bounced', 'pending',
        'stats_frozen_at',
    ];

    protected function casts(): array
    {
        return [
            'stats_frozen_at' => 'datetime',
        ];
    }
}
```

## Return Contract

`getCounts()` returns an array keyed by `campaign_id` with `CampaignStat` model instances exposing: `total`, `opened`, `clicked`, `sent`, `bounced`, `pending`. Campaigns without a stats row get zero-filled in-memory defaults.

Consumers:

| Caller | Properties read |
|---|---|
| `CampaignStatisticsService::get()` | `total`, `opened`, `clicked`, `sent`, `bounced` — computes ratios |
| `CampaignReportPresenter::getCampaignStats()` | `opened`, `clicked`, `sent`, `bounced` — computes ratios |
| `CampaignCancellationController::getSuccessMessage()` | `sent` only |

The `CampaignStat` model exposes the same property names via Eloquent attribute access. All consumers work unchanged.

## Write Points (Real-Time)

### 1. Message creation — `CreateMessages` pipeline

**File**: `src/Pipelines/Campaigns/CreateMessages.php`

Two paths: `dispatchNow()` (non-draft) and `saveAsDraft()` (draft). Both run inside `chunkById(1000, ...)`.

**Strategy**: Batch increment per chunk. Track actual creations per chunk (not all subscribers produce a new message — `findMessage()` deduplicates re-dispatches, `canSendToSubscriber()` deduplicates within a run, `firstOrCreate` may find existing).

Changes:
- `handle()`: creates the stats row via `CampaignStat::firstOrCreate(['campaign_id' => $campaign->id])` before processing
- `dispatch()` returns `bool` — true if a new message was created
  - `dispatchNow()`: returns `false` if `findMessage()` found existing, `true` after creating new message
  - `saveAsDraft()`: returns `$message->wasRecentlyCreated` from `firstOrCreate`
- `dispatchToSubscriber()`: counts creations per chunk, batch-increments `total` and `pending` after the loop

```php
// In dispatchToSubscriber()
$created = 0;
foreach ($subscribers as $subscriber) {
    if (! $this->canSendToSubscriber($campaign->id, $subscriber->id)) {
        continue;
    }
    if ($this->dispatch($campaign, $subscriber)) {
        $created++;
    }
}
if ($created > 0) {
    CampaignStat::where('campaign_id', $campaign->id)
        ->update([
            'total' => DB::raw("total + {$created}"),
            'pending' => DB::raw("pending + {$created}"),
        ]);
}
```

### 2. Message sent — `MarkAsSent`

**File**: `src/Services/Messages/MarkAsSent.php`

Called by `DispatchMessage::handle()` after successful send. One message at a time.

```php
// After save and MessageLookup::create
if ($message->source_type === Campaign::class) {
    CampaignStat::where('campaign_id', $message->source_id)
        ->update([
            'sent' => DB::raw('sent + 1'),
            'pending' => DB::raw('pending - 1'),
        ]);
}
```

Guard with `source_type` check — this service also handles automation messages.

### 3. Draft deletion on cancel — `BaseCampaignTenantRepository::deleteDraftMessages()`

**File**: `src/Repositories/Campaigns/BaseCampaignTenantRepository.php`

Count pending messages before deleting, then decrement. Wrap in transaction for safety.

```php
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
```

Note: `messages()` is a morphMany that includes `source_id = campaign.id`, so partition pruning applies and the lock is scoped.

## Write Points (Digest)

### 4. Webhook dirty flag — `EmailWebhookService`

**File**: `src/Services/Webhooks/EmailWebhookService.php`

Webhook handlers (`handleOpen`, `handleClick`, `handlePermanentBounce`) do NOT touch `sendportal_campaign_stats`. They update the message row (`opened_at`, `clicked_at`, `bounced_at`) as before, then push the campaign ID to a Redis dirty-set:

```php
// After saving the message, in handleOpen/handleClick/handlePermanentBounce
if ($message->isCampaign()) {
    Redis::sadd('sp:stats:dirty', $message->source_id);
}
```

The `Redis::sadd` call is placed outside the idempotency guards (e.g. outside `if (! $message->opened_at)`), so it fires on every webhook event — not just the first. This is harmless because the Redis set deduplicates, and the digest recalculates absolute counts.

The service also extracts `resolveMessage()` (consolidates the repeated `Message::where()` + `resolveSourceId()` + `first()` pattern) and `recordClickUrl()` as protected methods for testability.

### 5. Digest command — `CampaignStatsDigest`

**File**: `src/Console/Commands/CampaignStatsDigest.php`

```
php artisan sp:stats:digest {--freeze-after=}
```

Runs every N minutes (default 15) via `SendportalBaseServiceProvider`:

```php
$schedule->command(CampaignStatsDigest::class)
    ->cron('*/' . config('sendportal.stats.digest_interval', 15) . ' * * * *')
    ->withoutOverlapping();
```

Logic:

1. **Atomically consume the Redis dirty set** via `RENAME` + `SMEMBERS` + `DEL`. If the key doesn't exist (no activity since last digest), `RENAME` throws — catch and return an empty collection.

```php
$tempKey = 'sp:stats:dirty:processing';
Redis::rename('sp:stats:dirty', $tempKey);  // atomic swap
$dirtyIds = Redis::smembers($tempKey);
Redis::del($tempKey);
```

2. **Filter out frozen campaigns** — only recalculate stats for campaigns where `stats_frozen_at IS NULL`.

3. **Recalculate** `opened`/`clicked`/`bounced` from the messages table in batches of 100 campaign IDs (partition pruning via `source_id IN (...)`). Uses `CASE WHEN` syntax for PostgreSQL compatibility:

```php
DB::raw('SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) as opened')
```

Updates are absolute counts (not increments), so idempotent — no drift accumulation.

4. **Freeze old campaigns** — sets `stats_frozen_at = NOW()` for campaigns with `status_id = STATUS_SENT` and `updated_at` older than the freeze threshold (default 45 days). Frozen campaigns are skipped by future digest runs.

### Why digest instead of real-time webhook stats

| Aspect | Real-time | Digest |
|---|---|---|
| Stats freshness | Instant | ≤15 min for opens/clicks/bounces |
| Webhook handler changes | Stats table writes + idempotency guards | Single `Redis::sadd` (no idempotency needed) |
| Write load per webhook | 1 UPDATE on stats table | 0 |
| Risk of drift | Possible if a code path misses an increment | None — absolute recalculation |
| Reconciliation needed | Yes — safety net for drift | No — digest IS the reconciliation |

The 15-minute staleness is acceptable because the campaigns list page already cached for 60 seconds, and email marketing dashboards don't need sub-minute granularity for opens/clicks/bounces.

### Methods that do NOT affect stats

| Method | Why |
|---|---|
| `handleDelivery()` | `delivered_at` not tracked in stats |
| `handleComplaint()` | `complained_at` not tracked in stats |
| `handleFailure()` | Writes to `message_failures`, not message timestamps |

## Read Point — Rewrite `getCounts()`

**File**: `src/Repositories/Campaigns/BaseCampaignTenantRepository.php`

```php
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
```

Removed the 60-second `cache()->remember()` wrapper — the query is instant.

`$workspaceId` parameter kept in the interface signature for backward compatibility.

## Backfill Command

**File**: `src/Console/Commands/BackfillCampaignStats.php`

Not a migration — backfill is a one-time manual operation for existing data. For fresh installs, the stats table starts empty and gets populated naturally as campaigns are dispatched.

```
php artisan sp:stats:backfill {--chunk=100}
```

Finds all distinct `source_id` values from `sendportal_messages`, chunks them in batches (default 100) for partition pruning, and uses `CampaignStat::updateOrCreate()` per row. Uses `CASE WHEN` syntax for PostgreSQL compatibility. Safe to re-run (idempotent).

```php
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
    CampaignStat::updateOrCreate(
        ['campaign_id' => $row->campaign_id],
        ['total' => $row->total, 'sent' => $row->sent, ...]
    );
}
```

## Deployment Sequence

1. **Deploy migration** — `php artisan migrate` creates empty `sendportal_campaign_stats` table
2. **Run backfill** — `php artisan sp:stats:backfill` (manual, safe to re-run)
3. **Deploy code changes** — all write points + new `getCounts()` in a single release
4. **Run delta catch-up** — `php artisan sp:stats:backfill` again to catch events between step 2 and step 3

Between steps 2 and 3, the old `getCounts()` still reads from the messages table (no impact). After step 3, reads come from stats table and all writes update it. Step 4 catches the gap.

## Files Changed Summary

| File | Change | Risk |
|---|---|---|
| **New**: `Models/CampaignStat.php` | Eloquent model | None |
| **New**: `2026_04_15_000001_create_campaign_stats_table.php` | Schema migration | None |
| **New**: `Console/Commands/BackfillCampaignStats.php` | One-time backfill (manual) | Low |
| **New**: `Console/Commands/CampaignStatsDigest.php` | Digest command (scheduled) | None |
| **New**: `tests/Unit/Services/CampaignStatsTest.php` | 14 integration tests | None |
| **New**: `tests/Unit/Services/CampaignStatsUnitTest.php` | 12 pure unit tests (no DB/Redis) | None |
| `config/config.php` | `stats.digest_interval`, `stats.freeze_after_days` | None |
| `Pipelines/Campaigns/CreateMessages.php` | Stat init + batch increment, `dispatch()` returns bool | **Medium** |
| `Services/Messages/MarkAsSent.php` | Increment sent, decrement pending | Low |
| `Services/Webhooks/EmailWebhookService.php` | `Redis::sadd` in 3 methods, extract `resolveMessage()` + `recordClickUrl()` | Low |
| `Repositories/Campaigns/BaseCampaignTenantRepository.php` | Replace aggregate query + update `deleteDraftMessages()` | Low |
| `SendportalBaseServiceProvider.php` | Register commands + schedule digest | None |
| `tests/TestCase.php` | `Redis::fake()` | None |
| `tests/Unit/Repositories/CampaignTenantRepositoryTest.php` | Pre-populate `CampaignStat` | None |

6 new files, 8 modified files.

## Not In Scope

- `CampaignReportPresenter` chart data — opens-per-period time-series query is a different pattern
- Campaign model accessors (`getSentCountAttribute`, `getOpenRatioAttribute`, etc.) — still query messages via morphMany, could be refactored separately
- Message listing/pagination queries — different problem
