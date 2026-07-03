# GF Odoo Connector

Connect [Gravity Forms](https://www.gravityforms.com/) to [Odoo](https://www.odoo.com/) CRM and Helpdesk. Form submissions are synced automatically to Odoo as leads, contacts, and support tickets.

- **Version:** 1.4.1
- **Requires:** WordPress 6.4+ · Gravity Forms 2.5+ · PHP 8.0+
- **Tested with:** Odoo 19 Enterprise
- **License:** GPL-2.0-or-later

---

## Features

- **CRM sync**: create/update contacts (`res.partner`) and leads (`crm.lead`).
- **Helpdesk sync**: create support tickets (`helpdesk.ticket`).
- **Per-field mapping**: map each Odoo field from a form field, a fixed value, auto-fill, or turn it off.
- **Feed templates**: configure mappings once and apply them to many forms, with per-form overrides and smart label-based remapping.
- **Global CRM assignment**: set a default salesperson and sales team for *all* forms, with an optional "force on all forms" mode that ignores per-form overrides.
- **Smart lead routing**: classify generic contact submissions and route them to CRM (sales), Helpdesk (support), or skip vendor/spam, with a needs-review fallback. Hybrid engine: instant offline keywords plus an optional EU-friendly AI (Mistral, or a custom OpenAI-compatible endpoint) that only handles uncertain cases and runs in the background. Off by default; enable per feed and start in Log only mode.
- **Static lookups**: 251 countries and 9 industries resolved locally with zero extra API calls.
- **Source capture**: auto-fills the submission page URL into `utm.source`.
- **Asynchronous processing**: submissions return instantly; syncing runs in the background via Action Scheduler.
- **Automatic retries**: failed syncs retry on a backoff schedule (+5 min → +1 hr → +24 hr) before notifying you.
- **Two-way sync**: a secured webhook endpoint lets Odoo push ticket/lead updates back into entry notes.
- **Error log**: searchable log with manual retry, bulk resolve, CSV export, and optional email alerts.
- **Dashboard**: sync activity chart and a live connection status check.
- **Security**: API keys stored encrypted (AES-256-CBC); HMAC-SHA256 webhook signature verification.
- **Test mode**: tag all created Odoo records with a `[TEST]` prefix while you validate the setup.

## Requirements

- WordPress 6.4 or later
- Gravity Forms 2.5+ (with the Feed Add-On Framework)
- PHP 8.0+ with the OpenSSL extension
- Odoo 16+ Enterprise (tested on Odoo 19 Enterprise)
- An Odoo administrator API key

## Installation

1. Copy the `gf-odoo-connector` folder into `wp-content/plugins/`.
2. Activate **GF Odoo Connector** in **WordPress Admin → Plugins**.
3. Go to **GF Odoo Connector → Connection & API**.
4. Enter your Odoo URL, database name, login email, and API key.
5. Click **Test Connection** to verify.

> Generate an API key in Odoo under **Preferences → Account Security → New API Key**.

## Configuration

### Connect to Odoo

On **GF Odoo Connector → Connection & API**, provide:

| Setting | Description |
| --- | --- |
| Odoo URL | Your Odoo site root, e.g. `https://example.odoo.com` (no `/web/login`). |
| Database name | The Odoo database name shown on the login screen. |
| Login email | The login on your Odoo user profile (needed for the legacy JSON-RPC fallback). |
| API key | Your Odoo admin API key (stored encrypted). |

### Global CRM assignment (all forms)

Under **Connection & API → CRM assignment (all forms)**:

- **Default salesperson / sales team**: applied to new leads from every CRM form.
- **Force on all forms**: when enabled, every CRM form uses the global salesperson and sales team and any per-form override is ignored.

Individual feeds default to **"Use global default"** and only diverge when you set a specific salesperson/team on the feed itself.

### Add a CRM feed

1. Open a form → **Settings → GF Odoo Connector → Add New**.
2. Set **Module** to **CRM**.
3. Map the contact fields (name, email, phone, country, …) and lead fields (title, description, industry, source, priority).
4. (Optional) Override the global salesperson/sales team for this form.
5. Save the feed.

### Add a Helpdesk feed

1. Set **Module** to **Helpdesk**.
2. Choose a **Helpdesk Team** (required).
3. Map the ticket fields (subject, description, contact info, product details).
4. Save the feed.

### Feed templates

Templates let you configure a mapping once and reuse it across forms:

1. Go to **GF Odoo Connector → Feed templates → Add template**.
2. Pick a sample form to use as the mapping reference.
3. Configure all fields.
4. On any form feed, choose **Use template** and select your template.
5. Override individual fields per form as needed; fields are remapped automatically by label.

### Webhook (two-way sync)

1. Go to **GF Odoo Connector → Webhook** and copy the webhook URL.
2. In Odoo: **Settings → Technical → Automation → Webhooks**.
3. Create a webhook for `helpdesk.ticket` or `crm.lead` pointing at your URL.
4. Set a shared secret; Odoo must send it as an `X-Odoo-Signature` HMAC-SHA256 of the raw request body.

## Usage

Display a ticket's status on the front end with the shortcode:

```
[odoo_ticket_status]
```

It reads `entry_id` from the URL automatically (e.g. a confirmation redirect to `thank-you/?entry_id={entry_id}`).

## Updates

The plugin updates itself from this repository's **GitHub Releases**: no wordpress.org listing required. Updates appear on the WordPress **Plugins** screen just like any other plugin (with the **enable auto-updates** toggle).

How it works:

- The plugin periodically calls the GitHub API for the latest **published release** of `KelvinPH/gf-odoo-connector` and compares its tag to the installed version.
- If the release tag is newer, WordPress shows an update and installs the release's source ZIP on demand.

### Publishing a new version

1. Bump the version in `gf-odoo-connector.php` (both the header `Version:` and the `GF_ODOO_VERSION` constant), and update `CHANGELOG.md` / `README.txt`.
2. Commit and push to `main`.
3. Create a **GitHub Release** with a tag matching the version, e.g. `v1.2.0` (a leading `v` is fine, it's stripped when comparing). The release notes become the changelog shown in WordPress.
4. Within ~12 hours every site sees the update; use **Dashboard → Updates → Check again** to pull it immediately.

> **Important:** it must be a *published Release*, not just a pushed tag, because the updater reads `releases/latest`. The very first rollout of this self-update feature still has to be installed manually on each site; every release after that updates automatically.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history. Latest:

### 1.4.1
- Fixed false-positive ticket category warning when Inquiry Category syncs correctly to Odoo; smart routing no longer labelled Beta.

### 1.4.0
- Helpdesk feed configurator for InBody Europe ticket fields, HTML overview table in Issue Description, auto-detect issue field by Odoo label, product/category tag maps, and helpdesk teams from Odoo.

### 1.3.0
- Added Smart lead routing: a hybrid keyword + optional EU AI classifier that routes generic contact submissions to CRM (sales), Helpdesk (support), or skips vendor/spam, with a needs-review fallback. Off by default; enable per feed and run in Log only mode first.

### 1.2.1
- Reworked the admin submenu into a clean flat layout with group dividers; removed em dashes across the plugin.

### 1.2.0
- Self-update from GitHub Releases: updates appear on the WordPress Plugins screen.

### 1.1.2
- Fixed industry mapping for "Corporate & Enterprise"; synced the industry list to current Odoo values.

### 1.1.1
- Added a **"Force on all forms"** option for global CRM assignment.

### 1.1.0
- Global CRM assignment (default salesperson + sales team for all forms).
- Per-form CRM assignment now defaults to "Use global default".
- "Reset to defaults" button for plugin settings.
- More reliable dashboard connection status (live re-check when stale).
- Fixed salesperson/sales team not being sent with submissions.
- Fixed template-linked feeds showing empty override dropdowns.

## License

This plugin is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
