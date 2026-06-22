# GF–Odoo Connector Plugin — Project Planning

## Project overview

A WordPress plugin that connects Gravity Forms to Odoo 19 Enterprise Edition via the Odoo JSON-RPC API. The plugin is built as a Gravity Forms Feed Add-On, allowing users to configure per-form feeds that send submission data to either Odoo CRM (leads/contacts) or Odoo Helpdesk (tickets).

## Tech stack

- **WordPress** running locally at `localhost:10003`
- **Gravity Forms** (installed and active)
- **Odoo 19 Enterprise Edition** — JSON-RPC API authenticated with an administrator API key
- **PHP 8.x** — OOP, no external Composer dependencies
- **Vanilla JS + WP Admin UI** for the settings screens

## Plugin architecture

```
gf-odoo-connector/
├── gf-odoo-connector.php          # Main plugin bootstrap file
├── includes/
│   ├── class-gf-odoo-addon.php    # Extends GFFeedAddOn — main add-on class
│   ├── class-odoo-api.php         # JSON-RPC API client (auth, requests, error handling)
│   ├── class-crm-handler.php      # Creates/updates res.partner and crm.lead
│   ├── class-helpdesk-handler.php # Creates helpdesk.ticket
│   └── class-field-mapper.php     # Maps GF field values to Odoo field format
├── admin/
│   └── views/
│       ├── settings-global.php    # Global settings view (API key, Odoo URL)
│       └── feed-settings.php      # Per-feed config view (module, field mapping)
├── assets/
│   ├── js/admin.js                # Feed settings UI logic
│   └── css/admin.css
└── languages/                     # i18n placeholder
```

## Odoo API details

- **Base URL**: configurable (e.g. `https://yourodoo.com`)
- **Authentication**: API key via JSON-RPC `authenticate` with `api_key` parameter (Odoo 16+)
- **Endpoint**: `POST /web/dataset/call_kw`
- **Session**: After authenticate, session_id is stored and reused via cookie

### Key Odoo models

| Module   | Model               | Used for                        |
|----------|---------------------|---------------------------------|
| CRM      | `res.partner`       | Contact lookup / create         |
| CRM      | `crm.lead`          | Lead creation                   |
| Helpdesk | `helpdesk.ticket`   | Ticket creation                 |
| Helpdesk | `helpdesk.team`     | Team dropdown in admin UI       |

## Gravity Forms integration

- Extends `GFFeedAddOn` (requires GF 1.9+)
- Feed UI provides: module selector (CRM / Helpdesk), field mapping table, conditional logic
- Hook used: `process_feed()` — triggered after successful form submission
- Async processing via WP background processing to avoid timeout on slow Odoo responses

## Data flow

1. User submits a Gravity Forms form
2. GF triggers `process_feed()` on all active feeds for that form
3. Plugin reads the feed config (module, field mappings)
4. `OdooAPI` authenticates (cached session)
5. `FieldMapper` transforms GF entry values to Odoo field format
6. `CRMHandler` or `HelpdeskHandler` calls the Odoo API
7. On success: entry meta is updated with Odoo record ID
8. On failure: error is logged, entry is flagged for retry

## Admin settings

### Global settings (Settings > GF Odoo Connector)
- Odoo URL
- API key (stored encrypted in wp_options)
- Test connection button
- Error log viewer

### Per-feed settings
- Feed name
- Module: CRM or Helpdesk
- Field mapping: GF field → Odoo field (dynamic based on module)
- Conditional logic (built into GFFeedAddOn)

## Error handling strategy

- All API calls wrapped in try/catch
- Errors logged to a custom `wp_gf_odoo_errors` table (id, form_id, entry_id, error_message, timestamp, retried)
- Admin notice when errors exist
- Manual retry button per failed entry
- Optional: email notification on failure

## Development phases

| Phase | Description                              | Est. time |
|-------|------------------------------------------|-----------|
| 1     | Plugin scaffold + GFFeedAddOn bootstrap  | 1 day     |
| 2     | Odoo API client + authentication         | 1 day     |
| 3     | CRM handler (partner + lead)             | 1 day     |
| 4     | Helpdesk handler (ticket)                | 1 day     |
| 5     | Field mapper + feed UI                   | 2 days    |
| 6     | Error handling + logging                 | 1 day     |
| 7     | Testing + polish                         | 1 day     |

## Coding conventions

- PSR-4 inspired class naming: `GF_Odoo_Addon`, `Odoo_API`, `CRM_Handler`
- All strings translatable via `__()` with text domain `gf-odoo-connector`
- Nonces on all admin forms
- Sanitize all input, escape all output
- No direct DB queries except for the custom error log table (use `$wpdb` with prepared statements)
- Constants for plugin version, path, and URL defined in main file