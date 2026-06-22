=== GF Odoo Connector ===
Contributors: kelvinhuurman
Tags: gravity forms, odoo, crm, helpdesk, integration
Requires at least: 6.4
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect Gravity Forms to Odoo CRM and Helpdesk. Automatically sync form submissions to Odoo leads, contacts, and support tickets.

== Description ==

GF Odoo Connector is a Gravity Forms Feed Add-On that sends form submission data to Odoo 19 Enterprise. It supports:

* Odoo CRM: Create or update contacts (res.partner) and leads (crm.lead)
* Odoo Helpdesk: Create support tickets (helpdesk.ticket)
* Per-field mode configuration: map from GF field, set a fixed value, or auto-fill
* Feed templates: configure once, apply to multiple forms with per-form overrides
* Country and industry lookup via static maps (no extra API calls)
* Automatic retry with exponential backoff (5 min → 1 hour → 24 hours)
* Two-way sync: Odoo webhook updates GF entry notes
* Error log with manual retry and CSV export
* Sync dashboard with activity chart

== Requirements ==

* WordPress 6.4+
* Gravity Forms 2.5+ (with Feed Add-On Framework)
* PHP 8.0+
* PHP OpenSSL extension
* Odoo 16+ Enterprise (tested on Odoo 19 Enterprise)
* Odoo administrator API key

== Installation ==

1. Upload the `gf-odoo-connector` folder to `/wp-content/plugins/`
2. Activate the plugin in WordPress admin > Plugins
3. Go to GF Odoo Connector > Connection & API
4. Enter your Odoo URL, database name, login email, and API key
5. Click "Test Connection" to verify the connection
6. Open any Gravity Forms form > Settings > GF Odoo Connector to add a feed

== Configuration ==

= Setting up a CRM feed =

1. Go to your form > Settings > GF Odoo Connector > Add Feed
2. Select module: CRM
3. Configure contact fields (name, email, phone, country, etc.)
4. Configure lead fields (title, description, industry, source, priority)
5. Save the feed

= Setting up a Helpdesk feed =

1. Select module: Helpdesk
2. Select a Helpdesk Team (required)
3. Configure ticket fields (subject, description, contact info, product details)
4. Save the feed

= Using feed templates =

Templates allow you to configure a feed once and apply it to multiple forms.

1. Go to GF Odoo Connector > Feed templates > Add template
2. Choose a sample form to use as reference for field mapping
3. Configure all fields
4. On any form feed, select "Use template" and choose your template
5. Override specific fields per form as needed

= Webhook setup (two-way sync) =

1. Go to GF Odoo Connector > Webhook
2. Copy the webhook URL
3. In Odoo: Settings > Technical > Automation > Webhooks
4. Create a webhook for helpdesk.ticket or crm.lead with your URL and secret

== Changelog ==

= 1.1.1 =
* Added "Force on all forms" option for global CRM assignment (overrides per-form salesperson and sales team)

= 1.1.0 =
* Global CRM assignment: set a default salesperson and sales team for all forms
* Per-form CRM assignment now defaults to "Use global default"
* Reset to defaults button for plugin settings
* More reliable dashboard connection status (live re-check when stale)
* Fixed salesperson/sales team not being sent with submissions
* Fixed template-linked feeds showing empty override dropdowns
* Fixed field-mapping overrides wiping template values on save

= 1.0.0 =
* Initial release
* CRM and Helpdesk feed support
* Feed templates with per-form overrides
* Country and industry static lookup maps
* Async processing via Action Scheduler
* Automatic retry with exponential backoff
* Two-way sync via webhook
* Sync dashboard
* Error log with retry and CSV export
* Test mode, pre-launch checklist, and testing tools
* API key encryption (AES-256-CBC)

== Frequently Asked Questions ==

= Which Odoo versions are supported? =

Tested on Odoo 19 Enterprise. Should work on Odoo 16+ with API key support.

= Does it work with Gravity Forms Lite (free version)? =

No. The Feed Add-On Framework requires Gravity Forms paid version.

= What happens if Odoo is down when a form is submitted? =

The form submission always succeeds. The Odoo sync is queued as a background job and retried automatically up to 4 times over 24 hours.

= Can I use this on multiple forms? =

Yes. Each form can have multiple feeds (e.g. one for CRM, one for Helpdesk). Use feed templates to share configuration across many forms.

= Is the API key stored securely? =

Yes. The API key is encrypted using AES-256-CBC with your WordPress authentication keys before being stored in the database.
