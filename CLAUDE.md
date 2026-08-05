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

It deliberately avoids `KCRM_Controller_Base`, `KCRM_Admin_Screen_Trait`, `KCRM_Invoice`, and `KCRM_Invoice_Item` — that layer changes more as karks-crm's own front-end evolves, so staying off it insulates this add-on. `KCRM_Pkg_Controller_Base` (`includes/controllers/`) reimplements the small subset of screen helpers (URL building, redirects, notices) it actually needs instead.

### Boot sequence (`karks-crm-packages.php`)

- `class-kcrmpkg-db.php` and `class-kcrmpkg-activator.php` load unconditionally at top level (activation hook must be registered before `plugins_loaded`), since neither references any karks-crm symbol.
- Everything else loads inside `kcrmpkg_run()`, hooked to `plugins_loaded`, guarded by `class_exists( 'KCRM_Customer' )`. This is required, not just tidy: WordPress's plugin-file include order is alphabetical by path string, and `"karks-crm-packages/..."` sorts *before* `"karks-crm/karks-crm.php"` (`-` < `/`), so this plugin's main file can load before karks-crm's own. `plugins_loaded` guarantees every active plugin's main file has already been included.
- `admin_menu` registration runs at priority 20 (not the default), because `add_submenu_page( 'karks-crm', ... )` needs karks-crm's own `add_menu_page( 'karks-crm', ... )` to have already registered the top-level menu in the same `admin_menu` firing — and both plugins boot via `plugins_loaded` at the same default priority, so relative order isn't guaranteed otherwise.
- If `karks-crm` is deactivated after this plugin booted, `class_exists` guards degrade gracefully rather than fataling.

### Data layer

Two tables (`{prefix}kcrmpkg_packages`, `{prefix}kcrmpkg_package_usage`), names centralized in `KCRM_Pkg_DB` (`includes/class-kcrmpkg-db.php`). Schema/upgrades live in `KCRM_Pkg_Activator` (`includes/class-kcrmpkg-activator.php`), gated by the `kcrmpkg_db_version` option vs `KCRMPKG_DB_VERSION` — bump the latter when changing schema, and since `dbDelta()` never drops columns, any column removal needs an explicit one-time `ALTER TABLE` guarded by an `information_schema` check (see `drop_label_column_if_exists()` for the pattern).

`KCRM_Pkg_Package` and `KCRM_Pkg_Usage` (`includes/models/`) extend karks-crm's `KCRM_Model_Base` directly rather than reimplementing CRUD. **Hours remaining is always computed live** (`allotted_hours` minus `KCRM_Pkg_Usage::hours_logged()`), never cached on the package row — this matches karks-crm's own precedent for balance-due calculations and avoids a drift-on-delete/edit bug class. Don't add a cached/denormalized remaining-hours column.

### Admin vs. front-end: parallel, not shared

Staff can log/delete usage entries from two places, and the two code paths deliberately mirror rather than share implementation:

- `KCRM_Pkg_Admin_Packages` (`includes/admin/`) — full CRUD (packages + usage) in wp-admin, under the `karks-crm-packages` page slug. Handles its own routing (`?page=karks-crm-packages&view=...`) via `admin_init`.
- `KCRM_Pkg_Front_Usage` (`includes/front/`) — usage log/delete only (no package create/edit) from the front-end `/crm/` customer screen, via `template_redirect`. Uses its own `kcrmpkg_`-prefixed query args (`kcrmpkg_action`, `kcrmpkg_usage_id`) rather than the admin screen's `action`/`id`/`usage_id`, specifically so it can't collide with karks-crm's own `action=delete&id=` handling on that same customer-endpoint URL.

Both are rendered into karks-crm's customer-edit screen by `KCRM_Pkg_Customer_Section` (`includes/integration/`), which listens on two karks-crm extension points rather than one now that the front end has a tabbed customer profile: `render()` on `kcrm_customer_edit_after_sections` (wp-admin only, no-ops on the front end) gives wp-admin a read-only summary + a link to the full admin screen; `register_tab()` on `kcrm_customer_profile_tabs` contributes a whole "Packages" tab on the front end, which additionally renders the usage log and "Log Usage" form inline (styled with karks-crm's own `.kcrm-front-table` / `.kcrm-front-form` classes), scoped to one selected package via `?kcrmpkg_package_id=`. The two entry points share a private `render_summary()` helper for the cards/table so that markup can't drift between them. Front-end links/redirects back into this tab always carry `&tab=packages` (e.g. `KCRM_Pkg_Front_Usage::redirect_to_package()`) so submitting the usage form or deleting an entry lands back on the right tab instead of defaulting to Home.

If you change usage add/delete behavior, update both `KCRM_Pkg_Admin_Packages` and `KCRM_Pkg_Front_Usage` — they're independent implementations of the same logic, not a shared code path.

### PDF report

`KCRM_Pkg_PDF::stream_package_report()` (`includes/pdf/`) mirrors karks-crm's own `KCRM_PDF::stream_invoice()`: renders `templates/package-usage-pdf.php` to an HTML string via `ob_start()`, then converts with `\Dompdf\Dompdf` loaded from karks-crm's Composer autoloader (dies with an explanatory message if Dompdf isn't present rather than fataling). The template reuses `KCRM_Colors::get()` and `KCRM_Company::pdf_accent_color()` so the report's color scheme matches karks-crm's invoice PDF exactly.

### Security/validation conventions used throughout

- Every state-changing handler (`save()`, `delete()`, `add_usage()`, `delete_usage()`, `handle_pdf_download()`) calls `check_admin_referer()`/nonce verification itself and a `current_user_can( KCRM_CAPABILITY )` check, even when an outer dispatcher already inspected the request — the outer checks (`handle_actions()`) are read-only route dispatch on action-name only, not the real authorization.
- `// phpcs:ignore WordPress.Security.NonceVerification...` comments mark request-param reads that are intentionally pre-nonce-check (routing/view params, or reads immediately followed by the real nonce check) — not blanket suppressions. Follow the same justify-inline pattern rather than removing/broadening these.
