# Fresh Core 0.7.0 — Fresh Console Standard 1.0

- Single plugin, two screens: dashboard and connector management.
- Fresh Console header discovery, including inactive modules; individual plugins work without Fresh Core.
- Resolve native administration routes by their actual WordPress page callback implementation, not guessed slugs. Optional Fresh Admin Slug / Fresh Tabs supported.
- Correct compact card action layout and dedicated available-update action; maintain WordPress upgrader and authorizations.
- Fresh Crackers Poppins branding, primary #9EC415, hyperlinks #E40058, logo.
- Live read-only batch export status and indicative completion estimate. Keep the last successful manifest during failed or running exports.
- Publish sanitized plugin inventory to plugins/registry.json only on successfully published batch snapshots.
- Existing read, write, accounting, authentication and manifest endpoints remain unchanged.
- Distributor ZIP stable name fresh-core.zip; updater update.json version and hash must match this archive.

Note: WordPress plugin compatibility and live updater should be validated on a staging/controlled site. WP callback introspection may not cover custom admin screens dynamically registered outside admin_menu. In such cases set Fresh Admin Slug in plugin header.
