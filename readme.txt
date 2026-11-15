=== Karks CRM Packages ===
Contributors: karks88
Tags: crm, invoicing, maintenance, hours, pdf
Requires Plugins: karks-crm
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track maintenance-package hour allotments and usage for Karks CRM customers,
with a client-shareable PDF usage report.

== Description ==

Sells a flat-rate allotment of hours (e.g. "$200 for 4 hours")? This add-on
tracks the allotment, lets you log itemized usage against it (date, hours,
description), shows the running remaining balance, flags when a package goes
over its allotment (so you know to create a separate overage invoice in
Karks CRM), and generates a client-shareable PDF usage report.

Usage can be logged from wp-admin or from the front-end `/crm/` customer
screen -- staff don't need wp-admin access just to log hours. Creating and
editing packages themselves (allotted hours, price, billing period) stays
wp-admin-only.

This is a separate, decoupled plugin from Karks CRM itself, on purpose --
the two evolve independently. Its own database tables, its own admin
screens, its own PDF report. It depends on Karks CRM being active (declared
via the `Requires Plugins` header) and touches only the following from
core, nothing else:

* `KCRM_CAPABILITY` -- the capability required to manage this add-on's screens too.
* `KCRM_Context::get_current_company_id()` -- which company's data to show.
* `KCRM_Customer`, `KCRM_Company`, `KCRM_Service` -- read access (`find()`/`for_company()`) to pick a customer/service when creating a package.
* `KCRM_Model_Base` -- the generic CRUD base this add-on's own models extend.
* `KCRM_Colors::get()` and `KCRM_Company::pdf_accent_color()` -- so the PDF report matches Karks CRM's own invoice PDF styling.
* `KCRM_PDF::logo_data_uri( $company )` -- reuses the exact same logo-rendering logic as the invoice PDF.
* `\Dompdf\Dompdf` -- via Karks CRM's already-loaded Composer autoloader; this plugin does not vendor its own copy.
* The `kcrm_customer_edit_after_sections` action -- renders the "Packages" summary box on the wp-admin customer edit screen (that screen has no tabs).
* The `kcrm_customer_profile_tabs` filter -- contributes a "Packages" tab (summary, usage log, and "Log Usage" form) to the front-end `/crm/` customer profile screen.
* `KCRM_Front::is_crm_page()` and `KCRM_Front::endpoint_url()` -- so the front-end "Log Usage" / "Delete Usage" submissions are recognized and redirect back to the right front-end customer URL, matching the front-end's own `.kcrm-front-table` / `.kcrm-front-form` styling.

It deliberately does not touch `KCRM_Controller_Base`, `KCRM_Admin_Screen_Trait`,
`KCRM_Invoice`, or `KCRM_Invoice_Item` -- that layer is more likely to change
as Karks CRM's own front-end evolves, and staying off it means changes there
can't break this add-on.

== Database ==

Two tables, `{prefix}kcrmpkg_packages` and `{prefix}kcrmpkg_package_usage`.
Hours remaining is always computed live (allotted minus the sum of logged
usage), never cached, matching Karks CRM's own precedent for balance-due
calculations.

== Changelog ==

= 1.0.9 =
* Fixed a PHP 8.4 "fputcsv(): the $escape parameter must be provided" deprecation notice that was written into the Package Usage CSV export. The CSV now uses standard quote-doubling (RFC 4180) with no backslash escaping.

= 1.0.8 =
* Added a Package Usage report to the Karks CRM Reports screen. It lists every client package with its start and end dates and allotted vs. used hours, and shows the average and overall percentage of hours used. Includes a status filter (All / Current / Completed) and CSV export. Requires a Karks CRM version with the `kcrm_reports_views` filter.

= 1.0.7 =
* Hardening: `GET` parameters read through `sanitize_key()` are now unslashed first, for Plugin Check compliance. No behavior change.

= 1.0.6 =
* Fixed: logging or deleting package usage from the front-end customer profile, and the "Log Usage" link/package selector on that tab, could redirect back to a "Customer not found" page after Karks CRM's nonce hardening -- these links now carry the nav nonce Karks CRM 0.9.10.2 requires (via its new `KCRM_Front::nav_nonce_args()`). The usage entry itself was always saved correctly; only the redirect back was affected. Requires Karks CRM 0.9.10.2 or later.
* Corrected "Tested up to" (7.0 -> 7.1).

= 1.0.5 =
* The front-end "Packages" tab now separates expired/cancelled packages out of the main summary into a collapsed "Package History" section, so old packages don't clutter the current view. Each history row still links to its PDF report. The tab's badge count now reflects active/exhausted (current) packages rather than active-only.

= 1.0.4 =
* The front-end "Packages" tab now shows a count badge for active packages, matching Karks CRM's own "Jobs" and "Invoices & Payments" tab badges.

= 1.0.3 =
* Packages now auto-expire: a daily background check flips "Active" packages to "Expired" once their period end date has passed. "Cancelled" and "Exhausted" packages are left alone, since those are deliberate manual states.
* Added a "Download PDF Report" button to the front-end "Packages" tab, so staff can generate the client-shareable usage report without wp-admin access.
* The wp-admin Packages screen title now shows the customer's name when viewing a customer-scoped package list or editing one of their packages.

= 1.0.2 =
* The front-end "Packages" section is now its own "Packages" tab on the customer profile, matching Karks CRM's newly tabbed front-end customer profile (Home / Jobs / Invoices & Payments), via the new `kcrm_customer_profile_tabs` filter. Requires Karks CRM 0.9.5 or later. The wp-admin customer screen is unaffected -- it still shows the same read-only summary as before.

= 1.0.1 =
* Added front-end usage logging: the "Packages" summary box on the
  front-end `/crm/` customer screen now includes the usage log and a
  "Log Usage" form, so staff can log/delete usage entries without wp-admin
  access. Package create/edit remains wp-admin-only.

= 1.0.0 =
* Initial release.
