# Changelog

All notable changes to GF Odoo Connector are documented here.

## [1.4.0] - 2026-07-03

### Added

- Helpdesk feed configurator aligned with InBody Europe `helpdesk.ticket` fields: ticket category, company (`customer_id`), email, phone, state, country, serial number, DI number, manufacturing date, product model tags, and more.
- HTML overview table for the Issue Description tab (subject plus all mapped fields in a clean table layout).
- Auto-detection of the Issue Description Odoo field by label (no developer tooltip required); standard `description` is treated as Resolution on customised instances.
- Static maps for product model → `helpdesk.tag` (`tag_24`…`tag_46`) and ticket category → `ticket.category` (`category_12`…`category_16`), with API fallbacks.
- Helpdesk teams loaded from Odoo with a refresh button on the ticket fields section.

### Changed

- Smart routing ticket body field defaults to Auto (Issue Description) instead of `description`.
- Smart lead routing is no longer labelled Beta; the experimental banner is removed from settings.

## [1.3.0] - 2026-06-30

### Added

- Smart lead routing: optionally classify generic contact-form submissions and route them automatically to CRM (sales), Helpdesk (support), or skip vendor/spam, with a "needs review" fallback when unsure. Enable per feed under the form's Odoo feed settings.
- Hybrid engine: instant, offline keyword scoring decides the obvious cases (obvious spam never leaves the site), and an optional EU-friendly AI (Mistral by default, or a custom OpenAI-compatible endpoint such as Azure OpenAI in an EU region) resolves only the uncertain cases. The AI runs in the background worker, so form submissions stay instant, and falls back to keywords whenever it is disabled or unavailable.
- New Smart routing settings page: master on/off switch (off by default), Off/Log only/Enforce mode (defaults to Log only), engine choice, editable EN/NL/DE/FR keyword lists, spam thresholds, blocked email domains, default Helpdesk team, and needs-review / web-lead tag names. The AI API key is stored encrypted.
- Entry notes now record each routing decision (engine, bucket, matched keywords or AI reason), and a new "Skipped (smart routing)" sync status marks vendor/spam entries that were intentionally not synced.

## [1.2.1] - 2026-06-30

### Changed

- Reworked the admin submenu into a clean flat layout with subtle group dividers (removed the faint section labels and reordered items by everyday use).
- Removed em dashes across the plugin UI, code, and docs in favour of clearer punctuation.

## [1.2.0] - 2026-06-30

### Added

- Self-update from GitHub Releases: the plugin now checks `KelvinPH/gf-odoo-connector` for newer published releases and surfaces updates on the WordPress Plugins screen (including the auto-update toggle). No more manual uploads after the first install of this version.

## [1.1.2] - 2026-06-30

### Fixed

- Industry mapping: "Corporate & Enterprise" (and any value not in the static map) no longer fails to sync; the industry map was synced to the current Odoo `res.partner.industry` list (added "Corporate & Enterprise", "Others", "Paramedical"; removed stale "ODM"/"Online")

## [1.1.1] - 2026-06-22

### Added

- "Force on all forms" option for global CRM assignment: when enabled, every CRM form uses the global salesperson and sales team, ignoring per-form overrides

## [1.1.0] - 2026-06-22

### Added

- Global CRM assignment: set a default salesperson and sales team for all forms under Forms → Settings → GF Odoo Connector
- Per-form CRM assignment now defaults to "Use global default" and only overrides when explicitly set
- "Reset to defaults" button to restore all plugin settings, API key, and caches

### Changed

- Dashboard connection status now performs a lightweight live re-check when the cached status is stale, instead of showing "Not reachable" after a working test

### Fixed

- Salesperson and sales team are now reliably sent with submissions (per-feed settings were being overwritten by template resolution)
- Template-linked feeds now display the actual mapped field instead of empty override dropdowns, with auto-repair of corrupted overrides on load
- Field-mapping overrides no longer wipe template values on save (sparse overrides are merged, not replaced)

## [1.0.0] - 2026-06-04

### Added

- CRM feed: create/update res.partner and crm.lead
- Helpdesk feed: create helpdesk.ticket
- Per-field mode config (auto / from field / fixed / off)
- Feed templates with sample form and per-form overrides
- Smart remapping when linking forms to templates (label-based matching)
- Country lookup: 251 countries, static map, zero API calls
- Industry lookup: 9 industries, static map, zero API calls
- Source auto-mode: captures page URL → utm.source in Odoo
- Async processing via Action Scheduler (form submits instantly)
- Automatic retry: attempt 1 → +5min → +1hr → +24hr → fail notification
- Duplicate submission guard
- Two-way sync: webhook receiver (REST endpoint)
- Ticket status shortcode `[odoo_ticket_status]`
- Sync dashboard with 14-day activity chart
- Error log with retry, bulk resolve, and CSV export
- Odoo sync status column in GF entries list
- API key encryption (AES-256-CBC)
- Test mode with `[TEST]` prefix on all created records
- Pre-launch checklist
- Manual test submission tool and failure scenario tests
- Uninstall cleanup with "keep data" option

### Requirements

- WordPress 6.4+
- Gravity Forms 2.5+
- PHP 8.0+
- Odoo 19 Enterprise (tested)
