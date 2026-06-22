# Changelog

All notable changes to GF Odoo Connector are documented here.

## [1.1.1] — 2026-06-22

### Added

- "Force on all forms" option for global CRM assignment: when enabled, every CRM form uses the global salesperson and sales team, ignoring per-form overrides

## [1.1.0] — 2026-06-22

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

## [1.0.0] — 2026-06-04

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
