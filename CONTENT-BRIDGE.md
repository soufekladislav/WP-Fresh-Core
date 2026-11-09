# Fresh Core 0.5.0 – Universal Content Bridge

Content Bridge je součást Fresh Core, nikoliv samostatný WordPress plugin. Vznikl jako společný kontrakt pro Lindu, Bobana a další Fresh aplikace.

## Read routes

- `GET /wp-json/fresh-core/v1/content/export?page=1&per_page=75`
- `POST /wp-json/fresh-core/v1/content/preview-url`
- `GET /wp-json/fresh-core/v1/content/status?url=...`
- `GET /wp-json/fresh-core/v1/analytics/summary`

Používají standardní Fresh Core read autorizaci.

## Write route

- `POST /wp-json/fresh-core/v1/write/content/upsert`

Používá samostatný Fresh Core write key a globální přepínač Write bridge. Povolené typy jsou záměrně pouze `post` a `page`.

Doporučená pole requestu:

- `post_type`: `post` nebo `page`
- `post_id`: existující WordPress ID pro update (volitelné)
- `external_id`: stabilní ID položky v externí aplikaci; pokud `post_id` chybí, Fresh Core podle něj umí najít dříve vytvořený obsah
- `change_id`: unikátní ID konkrétní write operace; opakovaný request se stejným ID nevytvoří duplicitu
- `source_module`: např. `linda`; zapisuje se do eventu `content.upserted`
- `status`: `draft`, `pending`, `publish`
- `title`, `content`, `excerpt`, `slug`
- `taxonomies`: mapování taxonomy => term IDs/names
- `featured_media_id` nebo `featured_image_url`

SEO metadata zůstávají oddělená a zapisují se přes existující SEO provider bridge. Díky tomu může Linda publikovat obsah a Boban následně řídit SEO vrstvu bez přímého provázání obou aplikací.
