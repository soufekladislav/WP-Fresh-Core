# Fresh Core 0.7.8 – SEO cílová URL a bezpečné centrální aktualizace

## SEO write bridge
- `GET /wp-json/fresh-core/v1/seo/resolve?url=https%3A...`: autorizované čtení, vrací konkrétní WordPress SEO item s `id` podle **přesné URL**; cizí domény, nejednoznačné slugs a neexistující stránky odmítá.
- `POST /wp-json/fresh-core/v1/seo/apply`: zpětně kompatibilní `post_id` + nové volitelné `target_url`. Pokud Boban poslal již neplatné ID, Fresh Core opraví výběr výhradně podle jednoznačné a přesné URL; pokud nelze dohledat, nic nezapisuje a vrací trvalou chybu s důvodem.
- Totéž chování v `POST /seo/apply-batch` pro jednotlivé položky. Zabezpečení stále používá stávající read/write keys a serverový allow-list.
- Odpověď ze zápisu obsahuje `post_id`, `requested_post_id` a `resolved_by_url`; je možné ověřit, zda se ID opravilo.

## Aktualizační mechanismus
- Podpora WordPress `Update URI` hooku i starší transient vrstvy.
- Validace verze, absolutní HTTPS URL, shodné složky a názvu ZIP a povinného SHA-256.
- Hash je ověřen před instalací také při hromadném upgradu.
- Obnovení manifestu je provedeno **před** kontrolou WordPress aktualizací.
- Výchozí složka distribuce: `/wp-content/uploads/fresh-crackers/fresh-core/`.
- Samotný plugin (`fresh-core.zip`) neobsahuje `update.json` – ten patří vedle archivu na distribuční server.

## Provozní ověření po instalaci
1. Nahrát hotové `fresh-core.zip` a `update.json` do distribuční složky na FreshCrackers.cz (nejprve ZIP, poté update.json). Nepřepsat stávající soubory bez zálohy.
2. Na AdrenalineRace.eu a ostatních webech zkontrolovat aktualizace Fresh Core ve WordPressu; v případě potřeby první vydání 0.7.8 nainstalovat ručně přes WordPress administraci.
3. Na cílovém webu načíst `GET /seo/resolve?url=<přesná URL produktu>` s READ klíčem. Musí vrátit existující `id` a správnou URL.
4. U jednoho schváleného úkolu provést zápis přes Bobanovu novou verzi a ověřit SEO výsledek na webu. Neautomatizovat opakování starých 404 bez kontrolního zápisu.
5. V administraci Fresh Core zkontrolovat centrální verzi a použitý SHA-256; pokud není manifest dostupný, neprovádět automatický upgrade.

Pozn.: Neproběhl živý test na produkčním WordPressu ani ověření dostupnosti souborů na distribuční doméně. Vývojové testy používají simulované WP prostředí.
