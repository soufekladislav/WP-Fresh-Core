# Fresh Core 0.6.2 — performance and Fresh Crackers console (pilot)

## Included
- Safe cached SEO dashboard; SEO recalculation now queues batches of at most 35 IDs using WP-Cron, rather than processing all 5,896+ content records in one admin request.
- Fresh Crackers top-level dashboard with detection of active Fresh-named plugins, user-specific drag/drop and arrow reorder saved over nonce-protected AJAX. Other plugins remain independent; their own menus have NOT been hidden without tested adapters. Only the old Fresh Core Tools menu entry is hidden; its page URL remains available.
- SEO batch status and European Prague time localization for known timestamps; old SEO snapshots without timestamp are labelled unknown.
- Save-settings action no longer runs a synchronous full export; import SEO no longer cascades to full export; manual full export remains explicit and can be expensive.
- Explicit stored result of manual export attempts and SEO-queue actions.
- Dirty flag is no longer discarded if new changes arrive during export.
- Retains existing REST read/write/accounting endpoints and original Fresh Core updater.

## Known limits / do not misrepresent
- This is **not** a complete asynchronous implementation of the full static export. The explicit manual `Obnovit data teď` still runs synchronously and can be expensive; do not use on a busy production installation.
- WP-Cron batch performance depends on normal cron execution and is not guaranteed to finish instantly; queue scheduling can be interrupted during downtime.
- No application-wide profiler: telemetry shows whole request characteristics, not exact CPU/RAM attribution to third-party plugins.
- Fresh apps other than Core are discovered and ordered, but their navigation remains in original WordPress menus until their admin slugs/panels are integrated safely.
- No live WordPress integration or UI interaction tests executed. Syntax validated with PHP CLI.
- No migration or change of keys, data tables or updater URLs is intended.

## Rollout
Back up the active plugin and database. Deploy to staging first. Observe wp-admin, REST API, WooCommerce checkout, existing Fresh worker connections and check SEO batch status. Compare telemetry under similar traffic. Full static export remains manual/opt-in. Do not regenerate keys unless integrations can be updated.
