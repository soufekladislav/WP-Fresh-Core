# Fresh Core 0.7.7 – katalogový model (kompatibilní doplnění)

- `GET /wp-json/fresh-core/v1/commerce/catalog`: nadále čte produkty, ale doplněny veřejné identifikátory `identifiers.gtin` a `identifiers.brand`, popis, váha a informace o skladu. Stránkování podle stabilního ID; požadavek max. 100 produktů, Boban používá 25.
- `GET /wp-json/fresh-core/v1/commerce/variations`: nový stránkovaný export variant, max. 50 na stránku. Vrací ID varianty, ID rodiče, SKU, cenu, sklad, atributy (zobrazené názvy i původní slugs pro parametrické odkazy), fotografii a veřejné identifikátory. Čtení používá **stejnou existující autorizaci** Fresh Core Access Key.
- Účetní, fakturační, SEO write, ostatní oprávnění a konfigurace zůstávají v kódu beze změny.
- U importovaných produktů může být značka nebo EAN/GTIN uložen v nestandardních meta polích. V takovém případě se musí individuálně doplnit jejich mapování, nikoliv domýšlet identifikátor.

Ověřen PHP lint. Otestovat na testovací kopii webu a sledovat zátěž PHP/DB během stránkování.
