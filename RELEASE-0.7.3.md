# Fresh Core 0.7.3

- Zálohové výzvy Edy: zabezpečený samostatný endpoint /accounting/orders/{id}/advance-link a /advance-link/revoke.
- V detailu WooCommerce zobrazuje samostatně odkaz na zálohu a konečnou fakturu. Neukládá PDF.
- Oba zápisy podléhají Invoice write key, HTTPS, ověření cílového hostitele, částky a měny objednávky.
- Žádné změny platebních metod, objednávkových stavů ani načítacího API.
- Aktualizovat nejdřív Fresh Core, následně Edu 0.7.2.
