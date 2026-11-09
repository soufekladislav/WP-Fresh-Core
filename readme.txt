=== Fresh Core ===
Contributors: Fresh Crackers
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 0.7.5

Shared integration layer for Fresh WordPress projects and virtual employees.

Fresh Core exposes sanitized site data through read-only connectors and an optional allow-listed write bridge. Specialist intelligence remains in the specialist applications (Boban, Linda and future Fresh employees).

== Highlights in 0.6.0 ==

* Keeps existing READ/WRITE keys, manifest token and REST contracts for Linda, Boban and Karel unchanged.
* Adds a separate Accounting bridge for Eda with its own key and explicit opt-in.
* Adds invoice-ready WooCommerce order detail: billing, shipping, IČO/DIČ, line items, tax, discounts, shipping, payment and refunds.
* Adds central FreshCrackers update feed, manual update check and optional automatic updates.
* Verifies update ZIPs with SHA-256 when the central manifest provides a checksum.
* Author and product metadata now point to Fresh Crackers.

== Highlights in 0.5.2 ==

* REST-auth compatibility for security plugins that block anonymous REST before Fresh Core can verify its scoped key.
* Read connector may retry with the scoped `fresh_core_key` query parameter when custom headers are stripped.
* Conversion-aware commerce catalog for Linda and other Fresh employees.
* Product metadata is included in `/content/export`.
* New read-only `/commerce/catalog` and `/commerce/product/<id>` routes.

== Highlights in 0.5.0 ==

* Modular employee contract: Fresh Core is the common infrastructure; Boban is registered as the first specialist module and Linda can later register through the same `fresh_core_modules` contract.
* Shared event stream for content.saved, media.alt.updated and seo.updated, exported in modules/registry.json and available through the REST registry endpoint.
* SEO provider layer: Auto / Fresh Core native / AIOSEO / Yoast SEO / Rank Math.
* Reads and writes normalized title, description, canonical, noindex and nofollow through the selected supported provider.
* Safe migration/shadow mode: copy SEO metadata from a supported SEO plugin into Fresh Core without disabling the original plugin.
* Fresh Core native rendering is suppressed while another supported SEO plugin is active, preventing duplicate metadata during migration.
* WordPress Dashboard widget "Boban – SEO přehled" with local SEO counts and a link to Web Booster.
* SEO editor meta box on public content/products for title, description, canonical and robots fields.
* Existing ALT read/write bridge, image resolver, WooCommerce image coverage, idempotent changes and static connector remain compatible.
* Universal content bridge merged from Linda: paginated public content export, preview URL, content status, local analytics summary and allow-listed post/page upsert.
* Content writes are idempotent through `change_id`; optional `external_id` lets external Fresh apps update the same WordPress item without creating duplicates.
* Content writes emit `content.upserted` into the shared Fresh Core event stream, so Boban/Linda workflows can react to each other later.

== Important SEO migration note ==

Fresh Core 0.5.0 does NOT claim universal one-click replacement of a full SEO suite yet. It covers core metadata/robots/canonical and image ALT, but an existing plugin may still own advanced schema, social metadata and other site-specific features. Use the import/shadow workflow first and verify rendered output before disabling Yoast/AIOSEO/Rank Math.

== Security ==

* The static connector is read-only and its random path is revocable.
* The optional write bridge uses a separate secret key and can be disabled completely.
* Write endpoints accept only allow-listed operations.
* Generic wp_options/postmeta values, users, customer rows, orders, passwords and API secrets are not exported.

== Changelog ==

= 0.6.0 =
* Preserves legacy Fresh Core READ/WRITE and publishing contracts used by Linda, Boban and Karel.
* Adds isolated Accounting API for Eda with a dedicated X-Fresh-Core-Accounting-Key.
* Adds full invoice-ready WooCommerce order detail without exposing billing PII in the static connector.
* Adds central update checks from FreshCrackers, manual check, optional auto-update and SHA-256 validation.
* Changes plugin author to Fresh Crackers.

= 0.5.2 =
* Adds generic WooCommerce catalog endpoints for conversion-first assistants.
* Enriches product rows in content export with price, stock, rating, image and category data.
* Keeps the existing 0.5.0 read/write bridge fully backward compatible.
= 0.5.0 =
* Adds the Fresh module/event contract for Boban, Linda and future specialist modules.
* Adds module registry export and REST endpoint.
* Adds SEO provider selection and normalized provider reads/writes for Fresh Core, AIOSEO, Yoast SEO and Rank Math.
* Adds safe SEO metadata import into Fresh Core native shadow storage.
* Adds WordPress SEO dashboard widget and per-content SEO editor.
* Prevents Fresh Core native meta rendering while an external supported SEO provider is active.
* Preserves the existing safe SEO/ALT write bridge and connector compatibility.
* Integrates the generic Linda content bridge directly into Fresh Core; no second WordPress plugin is required.
* Adds `/content/export`, `/content/preview-url`, `/content/status`, `/analytics/summary` and protected `/write/content/upsert`.
* Adds idempotent content retries and stable `external_id` mapping for external publishing workflows.

= 0.4.6 =
* Extends image discovery to WooCommerce product galleries.
* Improves image/ALT context used by Web Booster.

= 0.4.5 =
* Adds rendered-image URL to WordPress attachment resolution through the protected /media/resolve endpoint.
* Supports common WordPress thumbnail URL variants.

= 0.4.4 =
* Adds page-role context for front page, posts page and WooCommerce shop/cart/checkout/account pages.
* SEO index exposes used image attachment IDs and ALT metadata.
* Adds allow-listed /media/apply-alt endpoint using the separate write key.

= 0.4.3 =
* Adds idempotent SEO write retries by change_id.

= 0.4.2 =
* Fixes near-refresh scheduling while keeping the recurring refresh fallback.

= 0.4.1 =
* Adds safe seo/apply-batch endpoint for allow-listed SEO metadata changes.

= 0.4.0 =
* Adds normalized SEO Bridge, SEO index and optional allow-listed SEO write API.

= 0.3.0 =
* Full Elementor / Elementor Pro document export.

= 0.2.0 =
* Static read-only JSON connector for content, taxonomies, database schema and Fresh module diagnostics.

== Privacy ==
User rows and billing/customer PII are not exported by the public/static connector. Sanitized order finance rows remain available for analytics; full billing data is available only through the opt-in Accounting bridge with its separate key.
Write access is disabled by default and uses a separate key from all read connectors.


== 0.5.3 ==
* Adds a sanitized WooCommerce order finance feed for Eda.
* Static manifest now declares `commerce.orders_feed_url` and exports `commerce/orders.json`.
* Feed contains order number/id, dates, status, totals, currency, payment method/payment state, transaction id and line items.
* Customer name, address, phone and e-mail are intentionally omitted.
* Adds authenticated REST endpoint `/wp-json/fresh-core/v1/commerce/orders`.
* WooCommerce order/payment/status changes schedule a near static-export refresh.
