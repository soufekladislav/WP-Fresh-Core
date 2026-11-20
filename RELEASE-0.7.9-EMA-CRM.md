# Fresh Core 0.7.9 – kontakty pro EMA CRM

**Změna:** přibyl jediný read-only endpoint přes již existující Accounting READ autorizaci používanou Edou. Nepřidává se nový key vault, přepínač ani veřejný export zákazníků. Eda fakturace a invoice WRITE mají nezměněná oprávnění.

GET /wp-json/fresh-core/v1/accounting/contacts?kind=users&after_id=0&limit=50
GET /wp-json/fresh-core/v1/accounting/contacts?kind=elementor_submissions&after_id=0&limit=50

HTTP hlavička: X-Fresh-Core-Accounting-Key = existující účetní READ klíč (nikoli fakturační WRITE klíč).

Odpověď obsahuje ok, available, site, kind, items, next_after_id, has_more. Limit 1–50, kurzor tvoří WordPress user ID nebo Elementor submission ID. Přeskakované záznamy mají pouze source_id + skip=true, aby kurzor plynule postupoval.

## Bezpečnost a data
- Endpoint používá Fresh_Core_Accounting::permission_or_error, takže běžný anonymizovaný read klíč není oprávněním pro zákaznická data.
- U WP users: e-mail, jméno, čas registrace; administrátorské a zaměstnanecké účty se přeskočí. Žádná hesla, role, reset tokeny, IP ani osobní metadata.
- U Elementor Pro: kontaktní pole s výslovně rozpoznaným ID email, name, first_name, last_name, phone, company a vybranými českými aliasy. Výstup neobsahuje zprávu, soubory, IP, tokeny ani všechna obecná políčka.
- Elementor formulář s libovolným ID pole emailu nebude automaticky rozpoznán. Pokud chce správce synchronizaci, použije custom_id email u kontaktního pole. Nepoužívat plošný SQL dump!
- Chybí-li Elementor Pro Submissions tabulky nebo je kolekce vypnutá, available=false a žádné kontakty.
- Žádný z těchto zdrojů nepotvrzuje marketingový souhlas. Ema eviduje zdroj odděleně od právního titulu kontaktovat.

## Instalace a kontrola
1. Záloha současného WordPress pluginu a databáze.
2. Nahrát kompletní Fresh Core 0.7.9 (včetně includes/class-fresh-core-crm.php).
3. Ověřit administraci Fresh Core a dosavadní připojení Eda Accounting – žádné znovuvytváření klíčů.
4. Ověřit nový GET na testovacích uživatelích a jednom Elementor formuláři účetním READ klíčem, bez ukládání payloadů do logu.
5. Potvrdit, že běžný Fresh Core read klíč na účetní kontaktní endpoint nedostane data a současná fakturace funguje.
6. Teprve poté připojit zdroj v Emě a ověřit zápis do CRM.
7. Otestovat desktop, mobil a stránkování přes 50+ záznamů, dvojí opakování bez změn zdrojového WordPressu.

## Testy v GitHub
PHP lint nad pluginem a malý mock smoke test endpointu, zdrojového cursoru a ochrany kontaktních polí. Zelené testy nenahrazují WordPress + Elementor Pro + WooCommerce produkční přejímku.
