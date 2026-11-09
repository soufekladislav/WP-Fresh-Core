# Fresh Core 0.6.1 – výkonnostní pilot, nikoli hotový komunikační systém

Tato verze je konzervativní výkonnostní pilot odvozený z dodaného ZIP 0.6.0. Neinstalovat bez zálohy a kontroly v testovacím prostředí.

## Změny
- Běžný dashboard už **nespouští úplný SEO index**. SEO souhrn lze spočítat ručně v Nástroje > Fresh Core. Do té doby se zobrazují nulové hodnoty s upozorněním; pro produkci doporučeno upravit UI na `—` místo nul.
- Automatický úplný statický export je **výchozím stavem vypnut** (`fresh_core_auto_full_export=no`). Manuální export a existující REST endpointy zůstávají. Externí čtečky statického manifestu mohou mít zastaralá data! Před nasazením zkontrolovat spotřebitele a v případě potřeby zapnout přechodně volbu `wp option update fresh_core_auto_full_export yes`.
- Automatický export s opt-in se spouští jen při dirty změně. Souběh plných exportů blokuje DB zámek (`fresh_core_export_lock`). Po fatálním pádu může zámek zůstat: před manuálním smazáním přes WP-CLI ověřit, že žádný export neběží.
- Telemetrie: vzorek ~2 % HTTP požadavků plus všechny >2 s, >256 MB nebo fatální chyby zachycené během shutdown. Zapisuje pouze oblast, trvání, špičkovou PHP paměť, počet SQL dotazů a stav; nikoliv IP, URL, cookies ani obsah SQL. Retence 30 dní, denní čištění nejvýše 500 záznamů. Data jsou vzorkovaná a neobsahují identitu konkrétního pluginu.
- V Nástroje > Fresh Core je jednoduchý přehled posledních 24 hodin; pro konzoli `wp eval 'print_r(Fresh_Core_Telemetry::summary(24));'`.
- Zůstává původní systém aktualizací, přístupové klíče a REST trasy.

## Co tato verze NEDĚLÁ
- Neměří skutečné serverové CPU/RAM přidělené hostingovým účtům. `memory_get_peak_usage` měří PHP paměť jednotlivých požadavků.
- Nepřiřazuje přesně spotřebu jiným pluginům (vyžaduje instrumentaci callbacků nebo profiler).
- Nerealizuje push-doručování do Edy/Lindy/Bobana, potvrzování a frontu (to vyžaduje samostatně navržený kontrakt a adresy endpointů).
- Negarantuje aktuálnost statického manifestu při režimu na vyžádání.

## Před nasazením
1. Zálohovat plugin i DB; nejprve nasadit na staging.
2. Ověřit, kdo odebírá `manifest.json` / `commerce/orders.json`. Pokud některý pracovník používá jen statický export, **nepřepínat na režim na vyžádání bez náhradní synchronizace**.
3. Ověřit funkčnost REST read/write, SEO, účetnictví, objednávek, publikování a aktualizací.
4. Po prvním otevření Nástroje > Fresh Core se vytvoří tabulka `<prefix>fresh_core_metrics`.
5. Pro měření porovnat TTFB admin, počet fatálních chyb a přehled `Fresh_Core_Telemetry::summary`.

## Rollback
Vrátit předchozí ZIP a obnovit konfiguraci automatického exportu. Telemetrická tabulka je oddělená a lze ji ponechat; nic automaticky nemaže existující data nebo logy.
