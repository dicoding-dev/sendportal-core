# Performance Analysis: `sendportal_subscribers` Table

## Overview

This document records the findings of a deep-dive query analysis on the `sendportal_subscribers` table.
It covers schema/index gaps, slow query patterns, unnecessary queries, and a prioritised improvement plan
with exact file references.

---

## 1. Current Schema & Indexes

**Migration:** `sendportal-core/database/migrations/2017_04_28_223840_create_subscribers_table.php`

| Column | Type | Index |
|---|---|---|
| `id` | int, auto-increment | PRIMARY KEY |
| `workspace_id` | unsigned int | Single index |
| `hash` | string (UUID) | UNIQUE |
| `email` | string | Single index |
| `first_name` | string, nullable | — |
| `last_name` | string, nullable | — |
| `meta` | json, nullable | — |
| `unsubscribed_at` | timestamp, nullable | Single index |
| `unsubscribe_event_id` | unsigned int, nullable | FK |
| `created_at` | timestamp | Single index |
| `updated_at` | timestamp | — |

**Related pivot table** `sendportal_tag_subscriber` already has:
- `idx_tag_subscriber` on `(tag_id, subscriber_id)`
- `idx_subscriber_tag` on `(subscriber_id, tag_id)`

---

## 2. Issues Found

### P0 — Critical

---

#### 2.1 Missing `workspace_id` filter in bulk sync (security + correctness)

**File:** `sendportal-core/src/Http/Controllers/Api/SubscribersController.php:107`

```php
// CURRENT — no workspace_id scope
DB::table('sendportal_subscribers')->whereIn('email', $emails)->get();
```

The initial email lookup in the `sync()` endpoint has no `workspace_id` filter.
This can pull subscribers from **other workspaces**, leaking data across tenants.
The follow-up query at line 160 does add the scope, making the pair inconsistent.

**Fix:**
```php
DB::table('sendportal_subscribers')
    ->where('workspace_id', $workspaceId)
    ->whereIn('email', $emails)
    ->get();
```

---

#### 2.2 Unbounded `->get()` on full export

**File:** `sendportal-core/src/Http/Controllers/Subscribers/SubscribersController.php:157`

```php
// CURRENT — loads entire table for this workspace into memory
$this->subscriberRepo->all(Sendportal::currentWorkspaceId(), 'id');
```

Called on every CSV export request. A workspace with tens of thousands of subscribers
will either time out or exhaust PHP memory.

**Fix:** Use a database cursor to stream rows one at a time:
```php
$this->subscriberRepo->cursor(Sendportal::currentWorkspaceId());
```
Or chunk the output with `chunkById()` and stream the response incrementally.

---

#### 2.3 Full-wildcard `LIKE` search — guaranteed full table scan

**File:** `sendportal-core/src/Repositories/Subscribers/BaseSubscriberTenantRepository.php:115-118`

```php
// CURRENT — leading % makes all three conditions non-sargable
->where('first_name', 'LIKE', '%' . $name . '%')
->orWhere('last_name',  'LIKE', '%' . $name . '%')
->orWhere('email',      'LIKE', '%' . $name . '%')
```

A leading `%` wildcard prevents the query planner from using any index.
Every search request forces a full scan of the subscribers table.

**Fix options (choose one based on requirements):**

| Option | Trade-off |
|---|---|
| Change to prefix search `'text%'` | Uses the `email` index; loses mid-string matching |
| Add MySQL `FULLTEXT` index on `(first_name, last_name, email)` and use `MATCH … AGAINST` | Stays in MySQL; requires MyISAM or InnoDB FULLTEXT |
| Integrate Laravel Scout (Meilisearch / Typesense) | Best UX; adds infrastructure dependency |

---

### P1 — High

---

#### 2.4 N+1 queries in CSV import loop

**File:** `sendportal-core/src/Services/Subscribers/ImportSubscriberService.php:28-43`

For every row in the import file, the loop issues:
1. `findBy(id)` — one query
2. `findBy(email)` if the ID lookup missed — second query
3. Lazy-loads the `tags` relationship on the result

A 1,000-row CSV triggers up to **3,000 queries**.

**Fix:** Batch-fetch all emails before the loop, then resolve from memory:
```php
$emails  = collect($rows)->pluck('email')->filter()->all();
$existing = Subscriber::where('workspace_id', $workspaceId)
    ->whereIn('email', $emails)
    ->with('tags')
    ->get()
    ->keyBy('email');

foreach ($rows as $row) {
    $subscriber = $existing->get($row['email']);
    // upsert logic here — no further queries needed
}
```

---

#### 2.5 Missing composite indexes

MySQL cannot combine two separate single-column indexes for a multi-column `WHERE` clause.
All of the queries below currently cause MySQL to pick one index and filter the rest in memory.

| Method | File:Line | WHERE / ORDER BY columns |
|---|---|---|
| `countActive()` | `BaseSubscriberTenantRepository.php:81` | `workspace_id AND unsubscribed_at IS NULL` |
| `getRecentSubscribers()` | `BaseSubscriberTenantRepository.php:88` | `workspace_id ORDER BY created_at DESC` |
| `getGrowthChartData()` | `MySqlSubscriberTenantRepository.php:16` | `workspace_id AND unsubscribed_at AND created_at` |
| `storeOrUpdate()` | `ApiSubscriberService.php:30` | `workspace_id AND email` |

**Recommended migration:**
```sql
-- Covers storeOrUpdate lookup and enforces email uniqueness per workspace at DB level
ALTER TABLE sendportal_subscribers
    ADD UNIQUE INDEX idx_workspace_email (workspace_id, email);

-- Covers countActive and status-filtered list queries
ALTER TABLE sendportal_subscribers
    ADD INDEX idx_workspace_unsubscribed (workspace_id, unsubscribed_at);

-- Covers getRecentSubscribers and date-sorted list queries
ALTER TABLE sendportal_subscribers
    ADD INDEX idx_workspace_created (workspace_id, created_at);

-- Covers getGrowthChartData (multi-condition chart query)
ALTER TABLE sendportal_subscribers
    ADD INDEX idx_workspace_unsub_created (workspace_id, unsubscribed_at, created_at);
```

> Note: `idx_workspace_email` (UNIQUE) also eliminates a race condition — email uniqueness
> per workspace is currently validated only at the application layer, allowing duplicates
> under concurrent inserts.

---

#### 2.6 Duplicate tag query in `edit()` action

**File:** `sendportal-core/src/Http/Controllers/Subscribers/SubscribersController.php:102`

The edit action loads the subscriber (with relationships), then issues a second query
to pluck tag IDs — even though the tags collection is already on the loaded model.

**Fix:** Replace the second query with the already-loaded relationship:
```php
// Before (two queries)
$subscriber = $this->subscriberRepo->find(...);
$tags = Tag::pluck(...); // separate query

// After (one query)
$subscriber = $this->subscriberRepo->find($workspaceId, $id, ['tags']);
$selectedTagIds = $subscriber->tags->pluck('id');
```

---

### P2 — Medium

---

#### 2.7 `GROUP BY` on a timestamp function bypasses index

**Files:**
- `sendportal-core/src/Repositories/Subscribers/MySqlSubscriberTenantRepository.php:26,34`
- `sendportal-core/src/Repositories/Subscribers/PostgresSubscriberTenantRepository.php` (same pattern)

```php
// CURRENT — function wrapper prevents index usage
->groupByRaw("date_format(created_at, '%d-%m-%Y')")
->groupByRaw("date_format(unsubscribed_at, '%d-%m-%Y')")
```

Wrapping a column in a function prevents the query planner from using the index on that column.

**Fix (MySQL):** Use `DATE(created_at)` and add a generated column index if the dashboard
query runs frequently:
```sql
ALTER TABLE sendportal_subscribers
    ADD COLUMN created_date DATE GENERATED ALWAYS AS (DATE(created_at)) STORED,
    ADD INDEX idx_workspace_created_date (workspace_id, created_date);
```

---

#### 2.8 No pagination on tag/subscriber relationship endpoints

**Files:**
- `sendportal-core/src/Http/Controllers/Api/TagSubscribersController.php:40`
- `sendportal-core/src/Http/Controllers/Api/SubscriberTagsController.php:40`

Both endpoints eager-load the entire relationship with no limit.
A tag with 100,000 subscribers returns all rows in a single response.

**Fix:** Add `paginate()` to both endpoints and document the `per_page` parameter in the API.

---

## 3. Improvement Plan (Prioritised)

| # | Priority | Issue | File | Action |
|---|---|---|---|---|
| 1 | P0 | Missing `workspace_id` in sync | `Api/SubscribersController.php:107` | Add `.where('workspace_id', $workspaceId)` |
| 2 | P0 | Unbounded export `->get()` | `Subscribers/SubscribersController.php:157` | Replace with cursor/chunked streaming |
| 3 | P0 | Full-wildcard LIKE search | `BaseSubscriberTenantRepository.php:115` | Prefix search or FULLTEXT index |
| 4 | P1 | N+1 in import loop | `ImportSubscriberService.php:28` | Batch fetch before loop |
| 5 | P1 | Missing composite indexes | DB schema | New migration with 4 composite indexes |
| 6 | P1 | Duplicate tag query in `edit()` | `Subscribers/SubscribersController.php:102` | Use already-loaded relationship |
| 7 | P2 | `GROUP BY` on timestamp function | `MySqlSubscriberTenantRepository.php:26,34` | Use `DATE()` + generated column index |
| 8 | P2 | No pagination on relationship endpoints | `Api/TagSubscribersController.php:40` | Add `paginate()` |
