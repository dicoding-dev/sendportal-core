# Optimize `sendportal_tag_subscriber`

**Status: ✅ Completed — 2026-04-15**

## Problem

- `sendportal_tag_subscriber`: ~25M rows
- Schema: `id` (PK, auto-increment), `tag_id`, `subscriber_id`, `created_at`, `updated_at`
- Foreign keys: `tag_id → tags.id`, `subscriber_id → subscribers.id`
- Only single-column FK indexes — no composite indexes for covering queries
- Duplicate `(tag_id, subscriber_id)` pairs found (no unique constraint existed)

## Decision: Composite Indexes, Not Partitioning

Partitioning was **not recommended** because:

1. **Bidirectional queries** — table is queried equally by `tag_id` (campaign dispatch) and `subscriber_id` (subscriber CRUD). No partition key benefits both directions.
2. **Small table** — at ~2-3GB total, it fits in InnoDB buffer pool.
3. **Would lose foreign keys** — MySQL doesn't support FKs on partitioned tables.
4. **Pivot table write pattern** — `sync` does bulk delete + insert; partitioning adds overhead.

## Implementation

### Migration 1: Add composite indexes (online, zero downtime)

```sql
ALTER TABLE sendportal_tag_subscriber
  ADD INDEX idx_tag_subscriber (tag_id, subscriber_id),
  ADD INDEX idx_subscriber_tag (subscriber_id, tag_id);
```

File: `2026_04_14_000001_add_composite_indexes_to_tag_subscriber.php`

Both directions now use covering indexes — no table lookups needed. This was deployed first to get immediate performance benefit with zero risk.

### Migration 2: Drop `id` column, promote composite PK

```sql
ALTER TABLE sendportal_tag_subscriber
  DROP FOREIGN KEY tag_id_foreign,
  DROP COLUMN id,
  DROP INDEX idx_tag_subscriber,
  ADD PRIMARY KEY (tag_id, subscriber_id),
  ADD FOREIGN KEY (tag_id) REFERENCES sendportal_tags(id);
```

File: `2026_04_14_000002_drop_id_from_tag_subscriber.php`

- `id` column served no purpose — Laravel's `BelongsToMany` never queries by pivot `id`
- `idx_tag_subscriber` becomes redundant since the new PK `(tag_id, subscriber_id)` covers it
- FK on `tag_id` must be dropped before dropping the index, then re-added after PK is set
- Estimated: 5-15 minutes, locks table (acceptable — only blocks admin operations, not email delivery)

### Data cleanup required

Duplicate `(tag_id, subscriber_id)` pairs existed because there was never a unique constraint. Before migration 2:

```sql
-- Check duplicates
SELECT tag_id, subscriber_id, COUNT(*) as cnt
FROM sendportal_tag_subscriber
GROUP BY tag_id, subscriber_id
HAVING cnt > 1;

-- Remove duplicates (keep oldest row)
DELETE t1 FROM sendportal_tag_subscriber t1
INNER JOIN sendportal_tag_subscriber t2
  ON t1.tag_id = t2.tag_id
  AND t1.subscriber_id = t2.subscriber_id
  AND t1.id > t2.id;
```

### No code changes required

All queries go through Laravel's `BelongsToMany` (`sync`, `attach`, `detach`, `whereIn`, `leftJoin`). Indexes are transparent to the application — MySQL optimizer automatically picks the best index. No application code was modified.

## Query Audit

### tag_id-scoped (Tag→subscribers)

| Location | Operation | Frequency |
|---|---|---|
| `CreateMessages::handleTag()` | `chunkById` active subscribers | **High** — campaign dispatch |
| `TagTenantRepository::syncSubscribers()` | `sync` | Medium — tag CRUD |
| `TagTenantRepository::destroy()` | `detach` all | Low — tag delete |
| `ApiTagSubscriberService` | `attach` / `sync` / `detach` | Low — API |
| `BaseCampaignTenantRepository::destroy()` | `detach` from campaign tag | Low — campaign delete |
| `Tag` model | `withCount('subscribers')` | Medium — tag listing |

### subscriber_id-scoped (Subscriber→tags)

| Location | Operation | Frequency |
|---|---|---|
| `BaseSubscriberTenantRepository::syncTags()` | `sync` | Medium — subscriber CRUD |
| `Subscriber::deleting` | `detach` all | Low — subscriber delete |
| `ApiSubscriberTagService` | `attach` / `sync` / `detach` | Low — API |
| `ImportSubscriberService` | merge tags via `sync` | Medium — bulk import |

### Both directions (JOIN)

| Location | Pattern | Frequency |
|---|---|---|
| `applyTagFilter()` | `LEFT JOIN on subscriber_id WHERE tag_id IN (...)` | Medium — subscriber listing |

## Write Pattern

Table is **write-on-admin-action, read-on-campaign-dispatch**:
- Writes: subscriber CRUD, tag CRUD, CSV imports, API calls (all user-initiated)
- Reads: campaign dispatch (`chunkById`), tag listing (`withCount`)
- No background jobs or scheduled commands write to this table
