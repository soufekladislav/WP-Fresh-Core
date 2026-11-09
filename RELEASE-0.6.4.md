# Fresh Core 0.6.4 — Fresh Crackers Console

- Stabilní zpětně kompatibilní rozšíření konzole z verze 0.6.3.
- Volitelná identifikace pluginu pomocí hlavičky `Fresh Console: yes`.
- Přímo deklarovaná administrační stránka `Fresh Admin Slug` a panely `Fresh Tabs`.
- Zpětně kompatibilní automatické rozpoznání aktivních pluginů pojmenovaných Fresh ...
- Když modul hlavičku nemá, konzole hledá odpovídající registrované administrační stránky.
- Pokud modul stránku nemá, otevře informační panel s návodem; nenaviguje na `plugins.php`.
- Upravený vzhled karet: správné zalamování, samostatná verze, tlačítka dole, font Poppins.
- Stávající registrační slugy WP admin, zpracování modulů a původní menu jsou zachovány; nedochází k vkládání cizích administračních formulářů do Fresh Core.
- Výchozí URL pro aktualizace Fresh Core: `https://www.freshcrackers.cz/wp-content/uploads/fresh-crackers/fresh-core/update.json`; staré výchozí nastavení se převede, vlastní URL nikoli.
- Verze 0.6.4 NEMĚNÍ batch export 0.6.3; před nasazením se vyžaduje záloha a staging test.

## Hlavička budoucích pluginů

```
 * Fresh Console: yes
 * Fresh Admin Slug: fresh-sales
 * Fresh Icon: dashicons-chart-line
 * Fresh Description: Obchodní nástroje
 * Fresh Tabs: Přehled|admin.php?page=fresh-sales;Nastavení|admin.php?page=fresh-sales-settings
```

`Fresh Admin Slug` musí být přesný slug již registrované WP admin stránky; používají se její původní capability a skripty.
