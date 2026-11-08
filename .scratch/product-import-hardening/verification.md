# Verification log: product import hardening

Manual checks on local MySQL (utf8mb4_unicode_ci) and Redis, recorded when taken. Automated coverage is the test suite.

## Phase 1 (2026-11-08)

- Backup before migrating: `~/delivery_app_backup_2026-11-08.sql` (96 MB, dump complete).
- Duplicate-cleanup migration: 12 s. `variant_attributes` 1,086,834 → 300,000 rows (786,834 deleted); duplicate (variant, name) pairs 300,000 → 0. Unique indexes `products_sku_unique`, `product_variants_sku_unique`, `variant_attributes_product_variant_id_name_unique` created.
- Benchmark, re-import of `products_test_100k.csv`, `QUEUE_CONNECTION=sync`, 500-row chunks: 91 s (~1,100 rows/s). Batch 8 completed, 100,000 processed, 0 failed; still 300,000 attributes, 100,000 variants, 20,017 products.
- Baseline before the change: batch 1 first import, 500-row chunks, 29.5 min (~56 rows/s); batch 5 re-import, 2000-row chunks, 62 min (~27 rows/s).

## Phase 2

- Verified with 4 Redis workers. Figures were not recorded.

## Phase 3

- Batch 10 retried with 4 Redis workers: processing → completed, 53 rows resolved.

## Phase 4

- Batch 12, `products_test_100k.csv` with changed attributes, 4 Redis workers: 100,000 processed, 0 failed, 0 InnoDB deadlocks, 60 s; 379,873 attributes afterwards, as expected.
- Batch 13, original file re-imported to restore the data: 100,000 processed, 0 failed, 0 deadlocks, 63 s; 300,000 attributes.
- Attribute names equal under the MySQL collation ("size" / "sizé"), run in a rolled-back transaction: the row fails, retryable, with the readable reason; nothing saved; counts unchanged.

## Phase 5

- `/api/test-import` absent from the router's route list. Upload limits and file copies are covered by tests; no manual MySQL run.
