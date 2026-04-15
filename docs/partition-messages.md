# Partition `sendportal_messages` by `source_id`

**Status: ✅ Completed — 2026-04-15**

## Problem

- `sendportal_messages`: 34M rows / 18.7GB data / 11.2GB index
- Every webhook event (open, click, delivery, bounce) queries `WHERE message_id = ?` without `source_id`
- 14 indexed columns cause write amplification and index bloat
- Campaign report queries aggregate across millions of rows per campaign

## Solution

Partitioned `sendportal_messages` by `HASH(source_id)` with 50 partitions. Introduced `sendportal_message_lookup` table to resolve `source_id` from `message_id` or `hash` for webhook and public-facing queries.

### Final Table State

```
sendportal_messages
  PRIMARY KEY (id, source_id)
  UNIQUE INDEX (hash, source_id)
  PARTITION BY HASH(source_id) PARTITIONS 50
  ~680K rows per partition, ~200MB index per partition

sendportal_message_lookup
  PRIMARY KEY (message_id)
  INDEX (source_id)
  INDEX (hash)
  ~9M rows, write-once read-many
```

## Code Changes

7 files changed across 5 commits:

| File | Change |
|---|---|
| `Models/MessageLookup.php` | New model — string PK (`message_id`), no timestamps |
| `Services/Messages/MarkAsSent.php` | Writes to lookup table on send (`message_id`, `source_id`, `hash`) |
| `Services/Webhooks/EmailWebhookService.php` | Added `resolveSourceId()`, adds `source_id` to all 7 webhook query methods |
| `Listeners/Webhooks/HandleMailgunWebhook.php` | `checkWebhookValidity` resolves `source_id` via lookup |
| `Http/Controllers/Subscriptions/SubscriptionsController.php` | Added `findMessageByHash()` helper, resolves `source_id` for 3 methods |
| `Http/Controllers/Webview/WebviewController.php` | Resolves `source_id` from hash via lookup |

### Backward Compatibility

All changes are backward-compatible. If the lookup table returns `null` (no row found for old messages), queries fall back to scanning all partitions — same performance as the original unpartitioned table. No data loss, no breakage.

## Migrations

| Migration | Purpose |
|---|---|
| `2026_04_14_000003_create_message_lookup_table` | Creates `sendportal_message_lookup` table |
| `2026_04_14_000004_partition_messages_table` | Recreates `sendportal_messages` with partitioning (fresh install only) |

The partition migration auto-detects existing data and skips if the table is not empty. Production partitioning was done manually via pt-archiver (see below). Foreign key checks are disabled during the migration since `message_failures` references `sendportal_messages.id` and MySQL does not support FKs on partitioned tables.

## Production Migration (executed 2026-04-15)

### Infrastructure

- Database: MariaDB on Galera cluster
- Storage: EBS gp3, expanded from 100GB → 150GB for migration headroom
- Other Galera nodes set to `wsrep_desync = ON` during the copy

### Step 1: Create lookup table

```sql
CREATE TABLE sendportal_message_lookup (
    message_id VARCHAR(255) NOT NULL PRIMARY KEY,
    source_id  INT UNSIGNED NOT NULL,
    hash       CHAR(36) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX sendportal_message_lookup_source_id_index (source_id),
    INDEX sendportal_message_lookup_hash_index (hash)
);
```

### Step 2: Backfill lookup table (~45 days of data)

Finding the cutoff ID via `WHERE sent_at >= ...` was too slow (>30s). Used binary search on the PK instead — see `scripts/find_cutoff_id.sh`.

```sql
INSERT INTO sendportal_message_lookup (message_id, source_id, hash, created_at)
SELECT message_id, source_id, hash, created_at
FROM sendportal_messages
WHERE id >= <cutoff_id>
  AND message_id IS NOT NULL;
```

- ~9M rows backfilled
- Completed in 9 minutes 19 seconds

#### Delta backfill (repeatable)

```sql
INSERT INTO sendportal_message_lookup (message_id, source_id, hash, created_at)
SELECT m.message_id, m.source_id, m.hash, m.created_at
FROM sendportal_messages m
LEFT JOIN sendportal_message_lookup l ON m.message_id = l.message_id
WHERE m.id >= <cutoff_id>
  AND m.message_id IS NOT NULL
  AND l.message_id IS NULL;
```

### Step 3: Create partitioned shadow table

```sql
CREATE TABLE sendportal_messages_new LIKE sendportal_messages;

ALTER TABLE sendportal_messages_new
  DROP INDEX sendportal_messages_hash_unique,
  ADD UNIQUE INDEX sendportal_messages_hash_unique (hash, source_id),
  DROP PRIMARY KEY,
  ADD PRIMARY KEY (id, source_id);

-- MariaDB requires PARTITION BY in a separate ALTER statement
ALTER TABLE sendportal_messages_new
  PARTITION BY HASH(source_id) PARTITIONS 50;
```

### Step 4: Copy data with pt-archiver

```bash
nohup pt-archiver \
  --source h=127.0.0.1,D=sendportal,t=sendportal_messages,F=/root/.pt-archiver.cnf \
  --dest h=127.0.0.1,D=sendportal,t=sendportal_messages_new,F=/root/.pt-archiver.cnf \
  --where "1=1" --limit 5000 --commit-each --no-delete \
  --progress 50000 --statistics \
  > /var/log/pt-archiver-messages.log 2>&1 &
```

- 34M rows copied
- Average speed: ~286K rows/min
- Completed in ~2 hours

Use `stdbuf -oL` before `pt-archiver` to get unbuffered log output. Otherwise monitor with `SELECT COUNT(*) FROM sendportal_messages_new`.

### Step 5: Gap catchup + atomic swap

```sql
INSERT IGNORE INTO sendportal_messages_new
SELECT * FROM sendportal_messages
WHERE id > (SELECT MAX(id) FROM sendportal_messages_new);

RENAME TABLE
  sendportal_messages TO sendportal_messages_old,
  sendportal_messages_new TO sendportal_messages;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE sendportal_messages_old;
SET FOREIGN_KEY_CHECKS = 1;
```

`FOREIGN_KEY_CHECKS = 0` required because `message_failures` has a FK referencing the old table. The partitioned table cannot have FKs (MySQL limitation). Referential integrity is enforced by application code (`Message::deleting` hook).

### Step 6: Purge binlogs

pt-archiver generated ~40GB of binlogs in `/var/log/mysql`. Galera does not use binlogs for replication (uses gcache), so safe to purge:

```sql
PURGE BINARY LOGS BEFORE NOW();
```

### Step 7: Re-sync Galera nodes

```sql
-- On desynced nodes
SET GLOBAL wsrep_desync = OFF;

-- Monitor sync progress
SHOW STATUS LIKE 'wsrep_local_state_comment';  -- wait for "Synced"
SHOW STATUS LIKE 'wsrep_local_recv_queue';      -- wait for 0
```

Note: Galera SST method is `rsync`, which locks the donor node during full state transfer. If gcache overflows and SST is needed, plan for donor downtime. Consider switching to `mariabackup` for non-blocking SST in the future.

## Disk Usage

| Phase | Disk used |
|---|---|
| Before migration (100GB volume) | ~80GB (80%) |
| After EBS resize to 150GB | ~80GB (53%) |
| During pt-archiver copy (both tables) | ~145GB (97%) |
| After dropping old table + purging binlogs | ~78GB (52%) |

### Final table sizes

```
sendportal_messages_new:  10,646 MB data + 12,951 MB index = ~23.6 GB
sendportal_messages_old:  18,757 MB data + 11,179 MB index = ~29.9 GB (dropped)
```

The partitioned table has smaller data (InnoDB reorganized during copy) but slightly larger indexes (50 separate B-trees vs 1).

## Lessons Learned

1. **MariaDB requires `PARTITION BY` in a separate `ALTER`** — cannot combine with index changes in one statement.
2. **EBS volumes cannot be shrunk** — size the expansion carefully. We needed 150GB (not 125GB) due to partition index overhead.
3. **`INSERT ... SELECT` with `WHERE sent_at >= ...` is slow** even with an index on `sent_at` — use binary search on PK to find the cutoff ID instead.
4. **pt-archiver output is buffered** when redirected to a file — use `stdbuf -oL` for real-time progress.
5. **Galera nodes should be desynced** (`wsrep_desync = ON`) during large bulk operations to avoid flow control issues.
6. **Binlogs accumulate during bulk copies** — purge after migration if using Galera (which uses gcache, not binlogs).
7. **`DROP TABLE` may fail with FK constraint errors** — use `SET FOREIGN_KEY_CHECKS = 0` when dropping the old table.
8. **Partitioned tables cannot have foreign keys** in MySQL/MariaDB — referential integrity must be enforced at the application level.

## Lookup Table Retention

Webhooks stop arriving ~30 days after send. Recommended 90-day retention:

```sql
DELETE FROM sendportal_message_lookup WHERE created_at < NOW() - INTERVAL 90 DAY;
```

## Query Audit

### Queries WITH `source_id` (benefit from partition pruning)

| Location | Pattern |
|---|---|
| `BaseCampaignTenantRepository::getCounts()` | JOIN on `source_id` + `source_type` |
| `BaseMessageTenantRepository::recipients()` | WHERE `source_type` + `source_id` |
| `BaseMessageTenantRepository::opens()` | WHERE `source_type` + `source_id` |
| `BaseMessageTenantRepository::clicks()` | WHERE `source_type` + `source_id` |
| `BaseMessageTenantRepository::bounces()` | WHERE `source_type` + `source_id` |
| `BaseMessageTenantRepository::unsubscribes()` | WHERE `source_type` + `source_id` |
| `BaseMessageTenantRepository::getFirstOpenedAt()` | WHERE `source_type` + `source_id` |
| `MySql/PostgresMessageTenantRepository::countUniqueOpensPerPeriod()` | WHERE `source_type` + `source_id` |
| `Campaign->messages()` morphMany | WHERE `source_type` + `source_id` |
| `CreateMessages::findMessage()` | WHERE `workspace_id` + `subscriber_id` + `source_type` + `source_id` |
| `CreateMessages::saveAsDraft()` | firstOrCreate with `source_id` |
| `deleteDraftMessages()` | `campaign->messages()->whereNull('sent_at')->delete()` |

### Queries WITHOUT `source_id` (use lookup table)

| Location | Pattern | Frequency |
|---|---|---|
| `EmailWebhookService` (×7 methods) | `WHERE message_id = ?` + resolved `source_id` | **High** — every webhook |
| `HandleMailgunWebhook` (×1) | `WHERE message_id = ?` + resolved `source_id` | High |
| `SubscriptionsController` (×3) | `WHERE hash = ?` + resolved `source_id` | Low — user-initiated |
| `WebviewController` (×1) | `WHERE hash = ?` + resolved `source_id` | Low — user-initiated |

### Not changed (acceptable full-partition scan)

- `Subscriber->messages()` delete cascade — rare admin operation
- `paginateWithSource()` — already scoped by `workspace_id`, paginated

## Provider Custom Header Analysis

Checked whether `source_id` could be extracted from webhook payloads instead of using a lookup table:

| Provider | Custom Headers in Webhooks? | Verdict |
|---|---|---|
| SES | Yes — `mail.headers` in SNS payload | ✅ Possible |
| Mailgun | Yes — `event-data.message.headers` | ✅ Possible |
| Postmark | No — only MessageID, RecordType | ❌ Needs lookup |
| Sendgrid | No — only `sg_message_id` | ❌ Needs lookup |
| Mailjet | No — only `MessageID` | ❌ Needs lookup |
| Postal | No — only `payload.message.id` | ❌ Needs lookup |

3 out of 6 providers don't return custom headers → lookup table is the only universal solution.
