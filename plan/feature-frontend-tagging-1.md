---
goal: Frontend UI Revamp and Product Tagging System for the pup Dashboard
version: 1.0
date_created: 2026-03-10
last_updated: 2026-03-10
owner: Web Platform Team
status: 'Planned'
tags: [feature, frontend, ui, tagging, migration]
---

# Introduction

![Status: Planned](https://img.shields.io/badge/status-Planned-blue)

This plan defines a full frontend UI revamp for the product dashboard and management interfaces, and adds a product tagging system to improve categorization and filtering. The implementation scope is strictly limited to UI presentation, product-tag data model support, and tag-based query/filter capabilities required by the frontend.

## 1. Requirements & Constraints

- **REQ-001**: Revamp all frontend UI surfaces in `index.php` and `manage.php` with a consistent design system while preserving existing product CRUD behavior.
- **REQ-002**: Add product tagging capability where each product can have zero or more tags.
- **REQ-003**: Support tag assignment in product create/edit flows in `manage.php`.
- **REQ-004**: Support tag display and tag-based filtering/search in `index.php` and `manage.php`.
- **REQ-005**: Keep existing chart modal, product stats, subscription controls, and scraping workflows functionally unchanged.
- **SEC-001**: All new SQL must use prepared statements; no string-concatenated dynamic user input.
- **SEC-002**: Sanitize all rendered tag values using `htmlspecialchars(..., ENT_QUOTES)`.
- **DAT-001**: Introduce normalized many-to-many schema for tags (`tags`, `product_tags`) instead of comma-separated strings.
- **CON-001**: Do not modify scraper execution logic in `puper.php` beyond optional non-breaking column selection safety if needed.
- **CON-002**: Do not change push notification payload contracts in `push_notifications.php`, `subscribe.php`, `unsubscribe.php`, or `sw.js`.
- **CON-003**: Do not alter existing endpoint URLs or query parameter names currently consumed by frontend JS unless backward compatibility is preserved.
- **GUD-001**: Reuse existing PHP page architecture (single-file PHP views) and avoid framework migration.
- **GUD-002**: Keep responsive behavior for mobile and desktop; no desktop-only redesign.
- **PAT-001**: Centralize new tag helper logic in dedicated PHP functions inside `manage.php` and mirrored query helpers in `index.php`.

## 2. Implementation Steps

### Implementation Phase 1

- GOAL-001: Add deterministic database support for tags and make product queries tag-aware.

| Task     | Description           | Completed | Date       |
| -------- | --------------------- | --------- | ---------- |
| TASK-001 | Create migration file `db/migrations/2026_03_10_add_product_tagging.sql` containing: `CREATE TABLE IF NOT EXISTS tags (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, slug VARCHAR(70) NOT NULL UNIQUE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)` and `CREATE TABLE IF NOT EXISTS product_tags (product_id INT NOT NULL, tag_id INT NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(product_id, tag_id), INDEX idx_tag_id(tag_id), CONSTRAINT fk_product_tags_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE, CONSTRAINT fk_product_tags_tag FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE)`. |           |            |
| TASK-002 | Add optional bootstrap SQL executor script `apply_tagging_migration.php` that reads and executes `db/migrations/2026_03_10_add_product_tagging.sql` idempotently using PDO transaction, with plain-text success/failure output. |           |            |
| TASK-003 | In `manage.php`, add helper functions: `normalizeTagsInput(string $raw): array`, `upsertTags(PDO $pdo, array $tagNames): array`, `syncProductTags(PDO $pdo, int $productId, array $tagIds): bool`, and `getProductTags(PDO $pdo, int $productId): array`. |           |            |
| TASK-004 | In `manage.php`, update `saveProduct($pdo, $product)` transaction flow to call `syncProductTags(...)` after product insert/update and roll back on failure. |           |            |
| TASK-005 | In `manage.php`, update `getProducts($pdo, $search, $status)` query to include aggregated tags using `GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ',') AS tags_csv` with `LEFT JOIN product_tags` and `LEFT JOIN tags`, and `GROUP BY p.id`. |           |            |
| TASK-006 | In `index.php`, update `getProductsWithStats($pdo, $search, $sortBy, $sortOrder)` to include tags aggregation and optional `tag` filter parameter from `$_GET['tag']` using prepared statement bindings. |           |            |

### Implementation Phase 2

- GOAL-002: Implement tag management UX in product create/edit/list interfaces and preserve existing CRUD controls.

| Task     | Description           | Completed | Date |
| -------- | --------------------- | --------- | ---- |
| TASK-007 | In `manage.php` create form block (around create section), add `input` named `tags` with hint text "Comma-separated tags" and validation rules: max 10 tags, max 30 chars each, allowed charset `[A-Za-z0-9\-\s]` before normalization to slug-safe values. |           |      |
| TASK-008 | In `manage.php` edit form block, prefill the `tags` input with existing tags from `getProductTags(...)` joined by `, `. |           |      |
| TASK-009 | In `manage.php` table row render, add visual tag chips under product URL using a new `renderTagChips(array $tags)` helper that escapes output and limits to 6 chips + "+N" overflow indicator. |           |      |
| TASK-010 | In `manage.php` toolbar, add tag filter control (`name="tag"`) and wire it to `getProducts(...)` so combined filters `search + status + tag` work simultaneously. |           |      |
| TASK-011 | In `manage.php` POST handlers, include `$_POST['tags'] ?? ''` for both create and update paths and pass to `saveProduct(...)` without changing delete/status/bulk actions behavior. |           |      |
| TASK-012 | Add CSS tokens and component styles in `manage.php` style block for chips (`.tag-chip`, `.tag-chip-list`, `.tag-filter`) without changing table data semantics. |           |      |

### Implementation Phase 3

- GOAL-003: Revamp dashboard/frontend visual system in `index.php` and keep interaction logic backward-compatible.

| Task     | Description           | Completed | Date |
| -------- | --------------------- | --------- | ---- |
| TASK-013 | Replace duplicated visual tokens in `index.php` and `manage.php` with harmonized CSS variable palettes (single naming convention, consistent shadows/radius/spacing) while keeping existing classes used by JS. |           |      |
| TASK-014 | Redesign `index.php` page header, stats cards, toolbar, and product cards with improved hierarchy, contrast, spacing, and responsive breakpoints at `<=1024px` and `<=640px`. |           |      |
| TASK-015 | In `index.php` product card markup, render tag chips from aggregated tags and add quick-filter links (`?tag=<slug-or-name>`) that preserve current search/sort query params. |           |      |
| TASK-016 | Update `index.php` toolbar to include active filter pills (search/sort/tag) with clear actions, without removing current search and sort controls. |           |      |
| TASK-017 | Keep modal/chart logic function names unchanged (`openModal`, `closeModal`, `switchChartType`, `setDateRange`, `loadChart`) and adjust only presentational classes/styles for modernized look. |           |      |
| TASK-018 | Ensure keyboard and accessibility baseline: visible focus states, color contrast for tags/status badges, and accessible labels for new tag controls. |           |      |

### Implementation Phase 4

- GOAL-004: Validate non-regression and complete rollout safely.

| Task     | Description           | Completed | Date |
| -------- | --------------------- | --------- | ---- |
| TASK-019 | Execute SQL migration in staging DB, verify `tags` and `product_tags` tables exist, and verify legacy products load with zero tags without errors. |           |      |
| TASK-020 | Manual verification matrix for `manage.php`: create product with tags, edit tags, clear tags, filter by tag, bulk action with tagged records. |           |      |
| TASK-021 | Manual verification matrix for `index.php`: tag chips render, tag filter in URL works, sorting and chart modal still work with/without tag filters. |           |      |
| TASK-022 | Validate endpoints unchanged by checking `get_history.php`, `subscribe.php`, `unsubscribe.php`, and `push_notifications.php` request/response contracts remain identical. |           |      |
| TASK-023 | Performance check: verify product list queries with tag joins remain under 300 ms for 1,000 products and 5,000 tag mappings with proper indexes. |           |      |

## 3. Alternatives

- **ALT-001**: Store tags as JSON in `products` table. Rejected because filtering, indexing, and referential integrity are weaker for MySQL query patterns used in this project.
- **ALT-002**: Add a single `category` column instead of tags. Rejected because one-value categorization does not meet multi-tag requirement.
- **ALT-003**: Frontend-only tags in browser localStorage. Rejected because tags must be shared across sessions/users and searchable server-side.
- **ALT-004**: Full frontend rewrite with SPA framework. Rejected due to constraint to avoid unnecessary architectural changes.

## 4. Dependencies

- **DEP-001**: MySQL/MariaDB support for `GROUP_CONCAT`, composite primary keys, and foreign keys.
- **DEP-002**: Existing PDO connection in `db_connection.php` for transactional tag sync operations.
- **DEP-003**: Existing Chart.js and SweetAlert CDN dependencies in `index.php` remain unchanged.

## 5. Files

- **FILE-001**: `manage.php` - Add tag CRUD helpers, request handling, query joins, tag filters, tag form fields, and tag chip UI styles.
- **FILE-002**: `index.php` - Add tag-aware query/filter logic, tag chip rendering, and full visual refresh of dashboard sections.
- **FILE-003**: `db_connection.php` - Optional strict error handling adjustment only if required for transaction rollback visibility (no credential changes).
- **FILE-004**: `db/migrations/2026_03_10_add_product_tagging.sql` - New schema migration for `tags` and `product_tags`.
- **FILE-005**: `apply_tagging_migration.php` - Optional migration executor for controlled rollout.
- **FILE-006**: `get_history.php` - No functional changes expected; regression verification target only.

## 6. Testing

- **TEST-001**: Create product with `tags="electronics, flagship, promo"`; verify 3 rows exist in `product_tags` mapping and chips render in both pages.
- **TEST-002**: Edit product tags from 3 tags to 1 tag; verify orphan mappings removed and only expected mapping remains.
- **TEST-003**: Submit invalid tags (empty fragments, duplicate names, overlength tag, illegal chars); verify normalization and server-side rejection behavior are deterministic.
- **TEST-004**: Filter by tag in `manage.php` and `index.php`; verify only mapped products return.
- **TEST-005**: Combined filter test (`search + status + tag`) in `manage.php` and (`search + sort + tag`) in `index.php`.
- **TEST-006**: Non-regression test for existing actions: delete product cascades to `product_tags`; bulk halt/active still functions; chart modal and history load unaffected.
- **TEST-007**: Responsive UI test at widths 1440, 1024, 768, 390 pixels for layout integrity and usable tag chips/controls.

## 7. Risks & Assumptions

- **RISK-001**: Tag joins can increase query complexity and slow list pages if indexes are missing.
- **RISK-002**: Existing rows with `NULL product_name` in `products` may expose edge cases in list rendering when tags are joined.
- **RISK-003**: Inline CSS + inline JS in large PHP files increases merge conflict risk during visual overhaul.
- **ASSUMPTION-001**: The `products` table has stable primary key `id` and InnoDB engine with foreign key support.
- **ASSUMPTION-002**: Deployment environment allows executing SQL migration once before enabling tag UI.
- **ASSUMPTION-003**: No third-party API contract depends on product tags, so schema addition remains internal.

## 8. Related Specifications / Further Reading

[MySQL 8.0 Reference: CREATE TABLE and Indexes](https://dev.mysql.com/doc/refman/8.0/en/create-table.html)
[MySQL 8.0 Reference: GROUP_CONCAT](https://dev.mysql.com/doc/refman/8.0/en/aggregate-functions.html#function_group-concat)
[Chart.js Documentation](https://www.chartjs.org/docs/latest/)