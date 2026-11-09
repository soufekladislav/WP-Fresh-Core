# Fresh Core 0.6.3 – dávkový export, provozní verze k integračnímu ověření

- Ruční "Obnovit data teď" nyní pouze založí úlohu. Neprovádí kompletní export v administračním HTTP požadavku.
- WP-Cron provádí vždy jednu omezenou část exportu (SEO po 25 položkách, obsah po 25, taxonomie po 100, Elementor dokumenty jednotlivě).
- `wp-content/uploads/fresh-core-connector/<token>/batch-status.json` publikuje `part_number`, `total_parts`, `has_more`, `is_last`, `status`.
- `manifest.json` se přepíše až po sestavení všech souborů a kontrole jejich velikostí a hashů. Soubory patří do neměnné složky `snapshots/<job_id>/` a manifest odkazuje na tyto verze.
- Status v administraci zobrazuje aktuální dávku a čas Praha. Aktualizační mechanismus pluginu a REST routes zůstaly zachovány.
- Původní exportní metoda zůstala v kódu jako nepoužívaná interní metoda; není volána tlačítkem ani cron hookem.

## Kontrola po instalaci

`wp plugin get fresh-core --field=version`

Otevřete Fresh Crackers → Fresh Core a jednou spusťte „Obnovit data teď“.

`wp eval 'print_r(Fresh_Core_Batch_Export::status());'`

Pokud úlohy neběží kvůli WP-Cron, spusťte **jednu dávku**:

`wp eval 'Fresh_Core_Batch_Export::process();'`

Nespouštějte dávky paralelně. Nemažte starý manifest ani výstupní snapshoty.

## Kompatibilita a omezení

- Konzumenti mají používat odkazy `files[].url` v novém manifestu. Starší pevné odkazy jako `/seo/index.json` se nezmění automaticky; vyžadují přechod na URL z manifestu.
- Dokud není úloha dokončena, starý `manifest.json` zůstává aktivní. Aktuální průběh je v `batch-status.json`.
- Jde o staticky zkontrolovaný balíček, který **neprošel skutečným end-to-end testem na cílovém hostingu**.
- Snapshot není účetní frontou s potvrzeným doručením. API Edu/Boban/Linda zůstává oddělené.
- Při velkých a často měněných katalozích je třeba navazující inkrementální synchronizace; tato verze optimalizuje plný export.
