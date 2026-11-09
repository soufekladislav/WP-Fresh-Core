# Fresh Core 0.7.2

- Oddělený `X-Fresh-Core-Invoice-Write-Key` pouze pro publikování/zneplatnění odkazu na PDF vytvořené Edou; nerozšiřuje generický Fresh Core write bridge.
- `POST /wp-json/fresh-core/v1/accounting/orders/{id}/invoice-link` (uložení čísla, URL a data na objednávce) a `/invoice-link/revoke`.
- Striktní HTTPS URL na `finance.freshcrackers.cz/faktura/<nahodny-token>` a kontrola měny a částky objednávky.
- HPOS-kompatibilní zápis do Woo order meta, idempotence a odmítnutí konfliktu čísel faktur.
- Odkaz zákazníkovi v přihlášeném detailu objednávky Woo a administrátorovi v administraci.
- Klíč je dostupný jen správci v administraci Fresh Core.
- Beze změn existujícího Accounting key pro čtení, WooCommerce checkoutu, ostatních Fresh Core konektorů.

Zálohovat původní plugin; po instalaci přenést **Invoice write key** do Edy, ne do veřejného manifestu. Autorizace a zápis nebyly otestovány na produkčním webu.
