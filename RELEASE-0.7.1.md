# Fresh Core 0.7.1 – Eda Accounting API / REST 401

- Allow `X-Fresh-Core-Accounting-Key` to clear an earlier generic WordPress REST 401 blocker, **only** for `/fresh-core/v1/accounting/*` and only for enabled bridge with a valid key.
- Continue to enforce the existing accounting route permission callbacks independently.
- Do not change existing public manifests, WooCommerce orders or read/write credentials.

This corrects the authorization integration, not third-party WAF or server-side HTTP 401 outside WordPress. Run a live ping test before allowing Eda to sync.
