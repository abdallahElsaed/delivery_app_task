# Spec: Product Import Hardening

Status: ready-for-agent

## Problem Statement

Admins and developers import product catalogues (products, variants, variant attributes) from CSV or JSON files, through the Filament admin panel or the `import:products` command. The import "works" for clean files on a fresh database, but in practice it:

- **Corrupts data on re-import.** Variant attributes are duplicated each time a file is imported again. The local database holds 1,086,834 attribute rows for 100,000 variants, and 300,000 (variant, attribute name) pairs are duplicated up to 6 times. Nothing in the database prevents duplicate product or variant SKUs either.
- **Is slow.** Every row looks up products and variants by SKU with a full table scan, because the SKU columns have no index. A 100k-row file took 29.5 and 62 minutes.
- **Reports the wrong status.** Batches can be marked completed while jobs are still being added. A header-only file, or an unsupported file type, leaves a batch "processing" forever. Progress shows 0% until the whole file has been read. Five local batches are stuck in "processing".
- **Loses or misreports rows.** CSV rows with the wrong column count are silently dropped. Failed rows show their position inside the chunk instead of their line in the file. Malformed attribute pairs are silently skipped.
- **Has unreliable retry.** A row that fails again leaves a stale "retrying" record plus a duplicate "failed" record. All failed rows are retried in one unchunked job outside the batch, so the batch status never reflects the retry. Retry is allowed while the original import is still running.
- **Accepts bad data.** Nothing validates the row values, so problems only surface as PHP or database errors. Rows are not saved atomically, so a product can be saved while its variant fails.
- **Exposes an unauthenticated route.** A test API route lets anyone start an import of a hard-coded file. File parsing happens inside the admin's HTTP request, so large files risk timeouts.

## Solution

Imports become correct, idempotent, observable and fast, with no change to the CSV format, the command signatures, or the admin panel URLs:

- **Re-importing a file is safe.** The database enforces SKU uniqueness and one value per (variant, attribute name). The file is the source of truth for the attributes of every variant it contains.
- **Uploading returns immediately.** A background job reads the file, counts the rows, and dispatches the chunks. The batch status moves predictably through `pending → processing → completed/failed`, and a file-level problem is reported as a failed batch with a reason.
- **Every rejected row is visible.** It is recorded with its real line number in the file, a clear reason, and whether it can be retried.
- **Retry is predictable.** It reuses the existing failed-row records, runs in chunks as part of a proper batch, and is only allowed once the import has finished.
- **Imports are an order of magnitude faster**, thanks to indexed lookups and fewer queries per row.

## User Stories

### Uploading and starting an import

1. As an admin, I want my upload to return immediately after the file is stored, so that large files never time out my browser request.
2. As an admin, I want to see a new batch in `pending` status right after uploading, so that I know the system has accepted my file.
3. As an admin, I want uploads larger than 50 MB to be rejected by the form, so that I don't start imports the system can't handle.
4. As an admin, I want files with an extension other than `csv` or `json` to be rejected with a clear error, so that I don't end up with a batch stuck in `processing`.
5. As an admin, I want a CSV that my operating system labels `text/plain` to still be accepted when its extension is `csv`, so that ordinary CSV exports work.
6. As a developer, I want `import:products {filePath}` to keep its signature, so that existing scripts and habits keep working.
7. As a developer, I want `import:products` to copy my file into the application's import storage before processing, so that the background worker never depends on a path that only exists on my machine.
8. As a developer, I want `import:products` to print the batch ID and return immediately, so that I can track the import in the admin panel.
9. As an admin, I want uploaded and command-supplied files to be kept on disk after import, so that I have an audit trail of what was imported.

### File-level validation

10. As an admin, I want a file with an unsupported format to mark the batch `failed` with a reason, so that I know why nothing was imported.
11. As an admin, I want an unreadable or malformed file to mark the batch `failed` with a reason, so that I can fix and re-upload it.
12. As an admin, I want a file with no data rows to mark the batch `failed` with the reason "empty file", so that it never stays `processing` forever.
13. As an admin, I want a file missing any of the 9 required headers to be rejected before any row is processed, so that a partial or wrong file can't change my catalogue.
14. As an admin, I want headers matched regardless of letter case, surrounding spaces, a UTF-8 byte-order mark, or column order, so that exports from Excel and other tools work.
15. As an admin, I want extra, unknown columns to be ignored, so that I can import files that carry additional data.
16. As an admin, I want a file-level failure to happen before any chunk job is dispatched, so that a rejected file never partially imports.

### Row-level validation

17. As an admin, I want each row validated before it touches the database, so that bad values are reported clearly instead of as PHP or SQL errors.
18. As an admin, I want rows with a missing or overly long SKU or name to fail with a reason, so that I can fix them.
19. As an admin, I want `product_status` limited to `active` or `inactive`, so that products never get unknown statuses.
20. As an admin, I want `currency` to be exactly 3 uppercase letters, so that prices are interpretable.
21. As an admin, I want `variant_price` to be a number from 0 to 99,999,999.99 with at most 2 decimal places, so that prices are never silently truncated or rejected by the database.
22. As an admin, I want `variant_stock` to be a whole number of at least 0, so that stock is never negative or fractional.
23. As an admin, I want a malformed attribute pair (missing `:`, empty key or empty value) to fail the row, so that a typo never silently deletes an attribute.
24. As an admin, I want the same attribute key appearing twice in one row to fail the row, so that the intended value is never ambiguous.
25. As an admin, I want a CSV row with the wrong number of columns to be recorded as a failed row, so that no row is ever silently dropped.
26. As an admin, I want every failed row to show its real line number in the original file, so that I can find and fix it.

### Saving products, variants and attributes

27. As an admin, I want product SKUs to be unique across the catalogue, so that a product is never duplicated.
28. As an admin, I want variant SKUs to be unique across the catalogue, so that a variant is never duplicated.
29. As an admin, I want each row saved atomically, so that a failure never leaves a product without its variant or a variant without its attributes.
30. As an admin, I want importing the same file twice to produce the same catalogue as importing it once, so that re-imports are safe.
31. As an admin, I want a variant's attributes replaced by the attributes in the file, so that the file is the source of truth for that variant.
32. As an admin, I want an empty `attributes` cell to remove all attributes of that variant, so that I can clear attributes through the file.
33. As an admin, I want products and variants that are not in the file to be left untouched, so that importing a partial file never deletes or deactivates the rest of the catalogue.
34. As an admin, I want a row whose variant SKU already belongs to a different product to fail with a reason naming that product, so that variants never silently move between products.
35. As an admin, I want later rows that repeat a `variant_sku` already seen in the same file to fail with a reason that points to the first occurrence's line, so that the final attributes are never decided by which parallel job finished last.
36. As an admin, I want the first row of a product to define its name, status and currency, and later rows in the same file that disagree to fail, so that product details are never decided by timing.
37. As an admin, I want a later file to overwrite values set by an earlier import of the same SKUs, so that the most recent file wins across separate imports.
38. As an admin, I want the existing duplicate attribute records cleaned up when the upgrade is deployed, so that the uniqueness rule can be enforced.

### Batch progress and status

39. As an admin, I want `total_rows` to be set before any chunk is processed, so that the progress figure is meaningful from the start.
40. As an admin, I want a batch to become `completed` only after every one of its chunks has finished, so that "completed" is always true.
41. As an admin, I want a batch to end as `completed` even if some rows failed, with the failed count visible, so that I can tell partial success from total failure.
42. As an admin, I want a chunk that crashes for infrastructure reasons to turn its unprocessed rows into retryable failed rows while the rest of the import continues, so that one crash never strands a batch half-imported.
43. As an admin, I want the four existing statuses (`pending`, `processing`, `completed`, `failed`) kept as they are, so that existing filters and badges keep working.
44. As an admin, I want a failed batch to show why it failed, so that I don't have to read log files.

### Retry

45. As an admin, I want retry to reuse the existing failed-row records instead of creating new ones, so that the failed-rows list never contains duplicates.
46. As an admin, I want each retried row to move from `retrying` to `resolved`, or back to `failed` with its new reason, so that nothing is left stuck in `retrying`.
47. As an admin, I want retried rows processed in chunks of the configured size, so that retrying many rows is as reliable as the original import.
48. As an admin, I want the batch to return to `processing` during a retry and to `completed` afterwards, so that the batch status reflects reality.
49. As an admin, I want the retry button hidden and the retry command refused while the batch is `pending` or `processing`, so that retries never race the original import.
50. As an admin, I want rows rejected for in-file conflicts (a repeated variant SKU, or conflicting product details) marked as not retryable and skipped by retry, so that retrying them can't overwrite what the first row set.
51. As an admin, I want rows that failed validation to stay retryable, so that retry behaves consistently even when it can't fix them.
52. As a developer, I want `import:retry {batchId}` to keep its signature and follow the same rules as the admin retry button, so that both paths behave identically.
53. As a developer, I want retry to match rows by their failed-row record identity rather than by searching the stored data, so that ordinary imports don't pay for an extra query on every row.

### Operations, configuration and security

54. As a developer, I want the chunk size to come from configuration (`IMPORT_CHUNK_SIZE`, default 500), so that I can tune it per environment without code changes.
55. As a developer, I want a chunk to finish well within the queue's `retry_after` window, so that a job is never handed to a second worker while it is still running.
56. As a developer, I want the unauthenticated test-import API route removed, so that nobody can trigger imports anonymously.
57. As a developer, I want the README and CLAUDE.md import sections to describe the real queue driver, chunk-size setting and import rules, so that the docs match the code.
58. As a developer, I want a measured before/after benchmark on the 100k-row file, so that the performance gain is shown with real numbers.
59. As a developer, I want automated tests for the whole import lifecycle, so that future changes can't silently break these guarantees.

## Implementation Decisions

### Modules

**Import entry points** (the upload page and the `import:products` command):
- Store the file on the application's local disk under the imports directory. The command copies its input file there first.
- Create the import batch in `pending` status, then dispatch a single background job that reads the file.
- No parsing happens in the request or the command.

**File-reading job** (new; runs on the `imports` queue):
- Picks the file-format strategy, validates the headers, and streams the rows.
- Assigns each row its real line number in the file.
- Runs the in-file consistency checks (repeated variant SKUs, conflicting product details).
- Sets `total_rows`, marks the batch `processing`, and dispatches all chunk jobs as one job batch.
- Any file-level error marks the batch `failed` with a reason and dispatches nothing.
- Rows rejected at this stage (wrong column count, in-file conflicts) are recorded as failed rows before chunks are dispatched, and count toward `failed_rows`.
- Collects chunks before dispatching the job batch, so completion callbacks can't run while jobs are still being added. If holding every chunk in memory is too costly, dispatch can be streamed instead, provided the batch's completion never fires early. Choosing the mechanism is left to the implementer.

**File-format strategies** (CSV and JSON):
- Keep the current interface's role: they yield rows.
- Each row carries its source line number, or its array index for JSON.
- The CSV strategy no longer drops rows with the wrong column count. It yields them flagged as malformed, so they can be recorded as failed rows.
- Header normalisation is the same for every format: trim, strip the byte-order mark, ignore case.
- The JSON strategy may still load the whole file into memory (streaming is out of scope).

**Row validation** (new, small): validates one normalised row against the rules in User Stories 18–24, using Laravel's validator, and returns either a clean row or failure reasons. Both the chunk job and retry use it.

**Chunk job:**
- For each row: validate it, then save it inside its own database transaction.
- Saving means: upsert the product by SKU, then the variant by SKU (failing if the SKU belongs to a different product), then **replace** the variant's attributes. Replace means deleting the attributes not in the row and upserting the rest.
- Failures are recorded with the real line number, a reason, and the retryable flag.
- Increments the batch counters once per chunk.
- Has a failure hook that turns its unprocessed rows into retryable failed rows with the reason "processing error".
- No longer runs the per-row "mark resolved" query during normal imports.

**Retry action** (new, shared by the command and the admin button):
- Refuses to run when the batch is `pending` or `processing`.
- Selects the batch's failed rows that are retryable, marks them `retrying`, and adjusts the counters.
- Sets the batch to `processing` and dispatches the rows in configured-size chunks as a job batch.
- Each retried row updates its own failed-row record to `resolved`, or back to `failed` with a new reason.
- The batch completion callback marks the batch `completed`.

**Job batch policy:** chunk and retry job batches allow failures. The completion callback sets the final status, so a crashed chunk never marks the whole batch `failed`. A batch is `failed` only for file-level errors.

**Admin panel:**
- The upload form gets a 50 MB limit and a `csv`/`json` extension check.
- The retry button is only visible when the batch is `completed` or `failed` and has retryable failed rows.
- The failed-rows list shows whether each row can be retried.
- The batch view shows the failure reason when there is one.

### Schema changes

- Unique index on product SKU.
- Unique index on variant SKU.
- Unique index on (variant, attribute name). Before it is created, the same migration deletes duplicate attribute records, keeping the highest ID of each pair, in batches so the table isn't locked for long.
- New nullable `error_message` column on import batches.
- New `retryable` flag on failed-row records, defaulting to true.
- If the existing attribute index (variant, name, value) becomes redundant once the unique index exists, it may be dropped.

### Behavioural contracts

- **Batch status:** `pending` (stored, not yet read) → `processing` (rows dispatched, or a retry running) → `completed` (every chunk finished, with or without failed rows) or `failed` (file-level error only).
- **Failed-row status:** `failed` → `retrying` → `resolved` or `failed`. Rows that are not retryable stay `failed`.
- **Counters:** `processed_rows + failed_rows` equals `total_rows` once a batch reaches `completed`. A retry moves rows from failed to processed as they succeed.
- **Within one file:** the first occurrence wins, both for a repeated variant SKU and for product details. Across separate imports, the last write wins.
- **Configuration:** chunk size comes from a new import config file, read from `IMPORT_CHUNK_SIZE` with a default of 500. This replaces the hard-coded default, including the uncommitted local change to 5000.

### Unchanged (backward compatibility)

- The CSV header names and the attribute format (`key:value|key:value`).
- The `import:products {filePath}` and `import:retry {batchId}` signatures.
- The admin panel URLs.
- The `imports` queue name.
- The four batch statuses.

### Removed

- The unauthenticated test-import API route.

## Testing Decisions

**What makes a good test here:**
- Tests check only external behaviour: the products, variants and attributes in the database; the batch's status, counters and error message; the failed-row records (line number, reason, status, retryable flag); and command output and exit codes.
- They never assert on internal classes, method calls or query counts.
- Each test uses a small fixture file that shows exactly one rule.

**Seam 1 (primary): the `import:products` and `import:retry` commands.**
- Run through the test harness with the `sync` queue and SQLite in-memory (`RefreshDatabase`).
- Covers file-level failures, header matching, row validation, replace semantics, empty attribute cells, a variant SKU that belongs to another product, in-file duplicate and conflict checks, line numbers, the batch lifecycle and counters, idempotent re-import, and every retry rule.

**Seam 2 (thin): the admin pages, tested through Livewire.**
- Covers upload size and extension rejection, a successful upload creating a `pending` batch and redirecting, and retry being unavailable while a batch is `pending` or `processing`.
- Does not re-test the pipeline.

**One direct test of the chunk job's failure hook.** It checks that a chunk which exhausted its attempts records its unprocessed rows as retryable failed rows. This can't be triggered naturally on the sync queue.

**Not automated, verified manually on local MySQL:**
- The duplicate-cleanup migration and the unique indexes against the existing 1.08M attribute rows.
- The before/after benchmark on the 100k-row file, with the same worker count, reporting duration and rows per second.
- Races between parallel workers, which the sync queue can't reproduce.

**First-test assumption to verify:** Laravel job batches, including `allowFailures` and completion callbacks, run correctly on the `sync` queue with SQLite. If not, stop and agree on an alternative before continuing.

**Prior art:** none for imports. The suite contains only Laravel's example tests. The new tests follow standard Laravel feature-test conventions, with fixtures in a dedicated test-fixtures directory for imports. Existing tests must stay green. The pre-existing PHP 8.5 PDO deprecation notice in the example feature test is out of scope.

**Gate per phase:** the full test suite passes and Pint reports no formatting issues on changed files.

## Out of Scope

- Streaming JSON parsing, which would need a new dependency.
- Detecting or recovering stale batches. The five stuck local batches are left as they are, as a follow-up.
- A full catalogue sync: deleting or deactivating products and variants that are missing from the file.
- Allowing only one import at a time.
- Automatic cleanup of uploaded files.
- A new `completed_with_errors` status.
- Editing failed rows in the admin panel before retrying.
- New file formats such as XLSX.
- Any public API for imports.

## Further Notes

**Delivery order.** Each phase is one commit on `feature/import-hardening`, made only after the user approves that phase's diff and test results. No push and no PR unless requested.

| Phase | Contents |
|---|---|
| 0 | Characterisation tests and fixtures; verify the sync-queue assumption. |
| 1 | Unique indexes with duplicate cleanup, per-row transactions, removal of the per-row "mark resolved" query, configurable chunk size. Then measure the 100k benchmark again and revisit the default chunk size. |
| 2 | Background file-reading job, file-level failures with `error_message`, real line numbers, CSV header normalisation, recording malformed rows, in-file duplicate and conflict checks, a race-free batch lifecycle, crash handling for chunks. |
| 3 | The shared retry action: reuse failed-row records, chunked job-batch retry, the retryable flag, blocking retry while a batch is running. |
| 4 | Row validation rules, replace semantics for attributes, failing a variant SKU that belongs to another product. |
| 5 | Remove the test route; upload limits; command file copy; README and CLAUDE.md updates (diff shown first). |

**Environment facts.**
- The local queue driver is Redis, with `retry_after` of 300 seconds. CLAUDE.md and the README wrongly say "database queue, 50 per chunk".
- The local database is MySQL; tests use SQLite in-memory.
- CLAUDE.md is gitignored, so documentation changes to it stay local.

**Risk.** The duplicate-cleanup migration deletes about 787k rows locally. Take a database backup before running it in any non-local environment.

**Decision record.** These decisions came from a grilling session (Q1–Q32, 2026-11-08). The main reversal from the original recommendation: attributes use **replace** semantics (the file is the source of truth for each variant it contains), not merge.
