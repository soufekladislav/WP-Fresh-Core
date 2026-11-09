# Fresh Core 0.6.5 – Stability & Console

- WooCommerce: restrict commerce and accounting order lists to `shop_order`, protecting against WC_Order_Refund being processed as an ordinary order. Accounting keeps refund details from parent orders.
- Fresh Console: clearer module panel navigation and context bar above recognized native plugin admin screens, settings and documentation links where provided by plugin metadata.
- Manual WordPress-wide plugin update check with nonce/capability checks and forced Fresh Core manifest refresh.
- Seven Fresh applications cards with artwork, purpose and direct links.
- Existing connector credentials, REST routes, manifests, exports and permission model remain unchanged.

Test on staging before publishing to production. The ZIP alone does not publish an update; upload both the archive and generated update.json to the indicated public paths.
