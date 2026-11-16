# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress add-on plugin (`karks-crm-packages`) for the separate **Karks CRM** plugin (`karks-crm`, declared via the `Requires Plugins` header). It tracks flat-rate maintenance-package hour allotments (e.g. "$200 for 4 hours"), lets staff log itemized usage against them, and generates a client-shareable PDF usage report.

There is no build system, package manager, or test suite in this repo (no `composer.json`, `package.json`, or PHPUnit config) — it's plain PHP loaded directly by WordPress. To exercise changes, activate the plugin in a WordPress install that also has `karks-crm` active and use wp-admin / the front-end `/crm/` screens directly.

## Release process

Pushing a tag matching `v*` triggers `.github/workflows/release.yml`, which stages the repo (respecting `.distignore`) into a `karks-crm-packages/` folder and zips it, so the release asset's top-level folder name is stable across versions (unlike GitHub's auto-generated source zip, which would break in-place updates via wp-admin). Bump `Version:` in `karks-crm-packages.php` and `KCRMPKG_VERSION` together before tagging.

## Architecture

### Deliberately decoupled from karks-crm core

This plugin is intentionally kept shallow-coupled to karks-crm — its own DB tables, own admin screens, own PDF renderer — so the two evolve independently. It touches only these karks-crm symbols, nothing else:

- `KCRM_CAPABILITY` — the capability gating this add-on's screens too.
- `KCRM_Context::get_current_company_id()` — which company's data to show.
- `KCRM_Customer`, `KCRM_Company`, `KCRM_Service` — read access (`find()`/`for_company()`).
- `KCRM_Model_Base` — the generic CRUD base both this add-on's models extend directly.
- `KCRM_Colors::get()` and `KCRM_Company::pdf_accent_color()` — so the PDF report matches karks-crm's invoice PDF styling.
- `KCRM_PDF::logo_data_uri( $company )` — reuses the exact invoice-PDF logo logic.
- `\Dompdf\Dompdf` — via karks-crm's already-loaded Composer autoloader; this plugin vendors nothing of its own.
- `KCRM_Front::is_crm_page()` / `KCRM_Front::endpoint_url()` — front-end route recognition/redirects.
- `kcrm_customer_edit_after_sections` — wp-admin only now (see below); the customer screen there has no tabs.
- `kcrm_customer_profile_tabs` — contributes the front-end "Packages" tab.
- `kcrm_reports_views` — contributes the "Package Usage" and "Upcoming Renewals" reports to the front-end Reports screen (see below).
- `kcrm_company_export_data` / `kcrm_company_import_data` — include packages in karks-crm's company export/import and so in Karks CRM Backups (see "Export / backup" below).
- `KCRM_Context`, `KCRM_Customer::find_many()`/`display_name()`, `KCRM_Front::nav_nonce_args()` — used by the report and the customer tab.
- `KCRM_Customer::jobs_for()` and the customer row's `company_id`/`parent_customer_id` — the front-end package form's Client/Job choice and the scope check in `KCRM_Pkg_Front_Packages`.

It deliberately avoids `KCRM_Controller_Base`, `KCRM_Admin_Screen_Trait`, `KCRM_Invoice`, and `KCRM_Invoice_Item` — that layer changes more as karks-crm's own front-end evolves, so staying off it insulates this add-on. `KCRM_Pkg_Controller_Base` (`includes/controllers/`) reimplements the small subset of screen helpers (URL building, redirects, notices) it actually needs instead.

### Boot sequence (`karks-crm-packages.php`)

- `class-kcrmpkg-db.php` and `class-kcrmpkg-activator.php` load unconditionally at top level (activation hook must be registered before `plugins_loaded`), since neither references any karks-crm symbol.
- Everything else loads inside `kcrmpkg_run()`, hooked to `plugins_loaded`, guarded by `class_exists( 'KCRM_Customer' )`. This is required, not just tidy: WordPress's plugin-file include order is alphabetical by path string, and `"karks-crm-packages/..."` sorts *before* `"karks-crm/karks-crm.php"` (`-` < `/`), so this plugin's main file can load before karks-crm's own. `plugins_loaded` guarantees every active plugin's main file has already been included.
- `admin_menu` registration runs at priority 20 (not the default), because `add_submenu_page( 'karks-crm', ... )` needs karks-crm's own `add_menu_page( 'karks-crm', ... )` to have already registered the top-level menu in the same `admin_menu` firing — and both plugins boot via `plugins_loaded` at the same default priority, so relative order isn't guaranteed otherwise.
- If `karks-crm` is deactivated after this plugin booted, `class_exists` guards degrade gracefully rather than fataling.

### Data layer

Two tables (`{prefix}kcrmpkg_packages`, `{prefix}kcrmpkg_package_usage`), names centralized in `KCRM_Pkg_DB` (`includes/class-kcrmpkg-db.php`). Schema/upgrades live in `KCRM_Pkg_Activator` (`includes/class-kcrmpkg-activator.php`), gated by the `kcrmpkg_db_version` option vs `KCRMPKG_DB_VERSION` — bump the latter when changing schema, and since `dbDelta()` never drops columns, any column removal needs an explicit one-time `ALTER TABLE` guarded by an `information_schema` check (see `drop_label_column_if_exists()` for the pattern).

`KCRM_Pkg_Package` and `KCRM_Pkg_Usage` (`includes/models/`) extend karks-crm's `KCRM_Model_Base` directly rather than reimplementing CRUD. **Hours remaining is always computed live** (`allotted_hours` minus `KCRM_Pkg_Usage::hours_logged()`), never cached on the package row — this matches karks-crm's own precedent for balance-due calculations and avoids a drift-on-delete/edit bug class. Don't add a cached/denormalized remaining-hours column.

### Package types: hours vs. term

`package_type` is `hours` (the default; every pre-1.2.0 row) or `term` (time-based, e.g. yearly hosting). Check it with `KCRM_Pkg_Package::is_term()`, not by comparing strings. Term packages:

- are defined only by `period_start`/`period_end` (end is required by `data_from_form()`); `allotted_hours` is forced to 0.
- use the usage table as a notes-only "Activity Log": entries are stored with `hours = 0`, and the description is required instead of hours. This rule lives in **both** `KCRM_Pkg_Admin_Packages::add_usage()` and `KCRM_Pkg_Front_Usage::add_usage()`.
- are left out of hours totals (customer summary cards) and the Package Usage report.
- are renewed by hand, never automatically, through `KCRM_Pkg_Package::renew()`. That's wp-admin `action=renew&id=`, or front-end `kcrmpkg_action=renew&kcrmpkg_package_id=`; both use nonce `kcrmpkg_renew_package_{id}`. It creates the next term with the same length, measured as a `DateInterval` so calendar months and years stay intact. It sets `renewed_by_id` on the old row through a conditional `UPDATE ... WHERE renewed_by_id IS NULL`, so a double-click can't create two renewals. The old row is otherwise left alone, and `expire_due()` archives it at its end date.
- get a "Renews in N days" / "Due for renewal" badge from `KCRM_Pkg_Package::renewal_badge_html()` (window `RENEWAL_WINDOW_DAYS`). Use that helper rather than re-implementing the badge.

### Admin vs. front-end: parallel, not shared

Staff can manage packages and usage from two places, and the two code paths deliberately mirror rather than share implementation:

- `KCRM_Pkg_Admin_Packages` (`includes/admin/`) — full CRUD (packages + usage) in wp-admin, under the `karks-crm-packages` page slug. Handles its own routing (`?page=karks-crm-packages&view=...`) via `admin_init`. A client-scoped list (`&customer_id=`) carries the client into "Add Package". List rows (`tr.kcrmpkg-row`) highlight on hover and open the package on click using their `data-href`; that's an inline style and script in `render_list()`, which ignores clicks on links. Keep `data-href` on any new row markup.
- `KCRM_Pkg_Front_Usage` (`includes/front/`) — usage log/delete from the front-end `/crm/` customer screen, via `template_redirect`.
- `KCRM_Pkg_Front_Packages` (`includes/front/`) — package create/edit/renew from that same screen, via `template_redirect`. Delete stays wp-admin-only because it also deletes the usage log. It only lets a package be saved for the profile's client or one of its Jobs, within the current company. It reads `kcrmpkg_profile_id` and checks `company_id`/`parent_customer_id`; keep that check when changing it.
- Both front handlers use their own `kcrmpkg_`-prefixed query args (`kcrmpkg_action`, `kcrmpkg_usage_id`, `kcrmpkg_package_id`, `kcrmpkg_view`) rather than the admin screen's `action`/`id`/`usage_id`, specifically so they can't collide with karks-crm's own `action=delete&id=` handling on that same customer-endpoint URL.

**One deliberate exception to "mirror, don't share":** package *form* validation and sanitizing live in `KCRM_Pkg_Package::data_from_form()`, called by both `KCRM_Pkg_Admin_Packages::save()` and `KCRM_Pkg_Front_Packages::save()`. The form has too many type-specific rules to keep two copies in sync. Each caller still does its own nonce, capability and scope checks and redirects. The two form templates (wp-admin `form-table` and front-end `.kcrm-front-form`) are separate, so add any new field to both.

Both are rendered into karks-crm's customer-edit screen by `KCRM_Pkg_Customer_Section` (`includes/integration/`), which listens on two karks-crm extension points rather than one now that the front end has a tabbed customer profile: `render()` on `kcrm_customer_edit_after_sections` (wp-admin only, no-ops on the front end) gives wp-admin a read-only summary + a link to the full admin screen; `register_tab()` on `kcrm_customer_profile_tabs` contributes a whole "Packages" tab on the front end, which additionally renders the usage log and "Log Usage" form inline, the Add/Edit Package form (`?kcrmpkg_view=add|edit`), and Renew for term packages (also in Package History, since the expiry cron moves overdue terms there) (styled with karks-crm's own `.kcrm-front-table` / `.kcrm-front-form` classes), scoped to one selected package via `?kcrmpkg_package_id=`. The two entry points share a private `render_summary()` helper for the cards/table so that markup can't drift between them. Front-end links/redirects back into this tab always carry `&tab=packages` (e.g. `KCRM_Pkg_Front_Usage::redirect_to_package()`) so submitting the usage form or deleting an entry lands back on the right tab instead of defaulting to Home.

If you change usage add/delete behavior, update both `KCRM_Pkg_Admin_Packages` and `KCRM_Pkg_Front_Usage` — they're independent implementations of the same logic, not a shared code path.

### Export / backup

`KCRM_Pkg_Backup_Bridge` (`includes/integration/`) follows the same pattern as karks-crm-customer-notes' `KCRM_Notes_Backup_Bridge`.

- **Export** (`kcrm_company_export_data`): adds a `kcrmpkg` key containing `db_version` and `packages`. Each package has its usage nested inside, keyed by the original package/customer/service ids.
- **Import** (`kcrm_company_import_data`):
  - Remaps customer and service ids through core's id maps.
  - Keeps the created/updated timestamps.
  - Relinks `renewed_by_id` in a second pass, since a renewal can be imported after the package it renews.
  - Skips rows whose client isn't in the file.
  - Whitelists type and status, and forces hours to 0 for term packages.
- **In-place restore with a `kcrmpkg` key** (`$target_company_id` set): replaces the company's packages and usage.
- **In-place restore without the key** (an older backup): never deletes packages. Instead `relink_existing()` remaps the existing packages' `customer_id`/`service_id` through the same maps. Core has just recreated every customer and service with new ids, and a restore is same-site, so the file's old ids are this site's own.

**When you add a column to either table, add it to both `export_packages()` and `import_packages()`.** Otherwise it's silently lost on every export/import/restore.

### PDF report

`KCRM_Pkg_PDF::stream_package_report()` (`includes/pdf/`) mirrors karks-crm's own `KCRM_PDF::stream_invoice()`: renders `templates/package-usage-pdf.php` to an HTML string via `ob_start()`, then converts with `\Dompdf\Dompdf` loaded from karks-crm's Composer autoloader (dies with an explanatory message if Dompdf isn't present rather than fataling). The template reuses `KCRM_Colors::get()` and `KCRM_Company::pdf_accent_color()` so the report's color scheme matches karks-crm's invoice PDF exactly.

### Package Usage report

`KCRM_Pkg_Report` (`includes/reports/`) adds a report to karks-crm's front-end Reports screen (`/crm/reports/?view=packages`) through the `kcrm_reports_views` filter, plus a CSV export via `admin_post_kcrmpkg_export_report_csv`. It lists every package for the company with hours used, filtered by `?kcrmpkg_status=` (all/current/completed). Its private `rows()` method feeds both the screen and the CSV so they can't drift. Hours come from `KCRM_Pkg_Usage::hours_logged_for_packages()`, one grouped query that is still computed live. The averages leave out cancelled packages and 0-hour packages and don't cap overages. "Average" is the mean of each package's %, and "Overall" is total used ÷ total allotted. On a karks-crm version without the filter, the report simply doesn't appear. It covers hours packages only.

`KCRM_Pkg_Renewals_Report` (`includes/reports/`) adds a second report through the same filter: "Upcoming Renewals" (`/crm/reports/?view=renewals`). It lists term packages that are neither renewed nor cancelled (`KCRM_Pkg_Package::renewals_for_company()`), soonest end date first, filtered by `?kcrmpkg_window=` (30/60/90/overdue/all). CSV export runs through `admin_post_kcrmpkg_export_renewals_csv`. Like the usage report, a private `rows()` feeds both the screen and the CSV. The report only lists packages; renewing happens in wp-admin.

### Security/validation conventions used throughout

- Every state-changing handler (`save()`, `delete()`, `add_usage()`, `delete_usage()`, `handle_pdf_download()`) calls `check_admin_referer()`/nonce verification itself and a `current_user_can( KCRM_CAPABILITY )` check, even when an outer dispatcher already inspected the request — the outer checks (`handle_actions()`) are read-only route dispatch on action-name only, not the real authorization.
- `// phpcs:ignore WordPress.Security.NonceVerification...` comments mark request-param reads that are intentionally pre-nonce-check (routing/view params, or reads immediately followed by the real nonce check) — not blanket suppressions. Follow the same justify-inline pattern rather than removing/broadening these.
