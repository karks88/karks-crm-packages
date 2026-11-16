=== Karks CRM Packages ===
Contributors: karks88
Tags: crm, invoicing, maintenance, hours, pdf
Requires Plugins: karks-crm
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track maintenance-package hour allotments and term-based packages (e.g. yearly
hosting) for Karks CRM customers, with a client-shareable PDF usage report.

== Description ==

Sells a flat-rate allotment of hours (e.g. "$200 for 4 hours")? This add-on
tracks the allotment, lets you log itemized usage against it (date, hours,
description), shows the running remaining balance, flags when a package goes
over its allotment (so you know to create a separate overage invoice in
Karks CRM), and generates a client-shareable PDF usage report.

Packages can also be term-based instead of hour-based -- e.g. web hosting
that renews each year. A term package is defined by its start and end
dates, keeps a notes-only activity log, shows a "Renews in N days" / "Due
for renewal" badge as its end date approaches, and can be renewed into the
next term with one click.

Packages can be added, edited and renewed, and usage logged, from wp-admin
or from the front-end `/crm/` customer screen's "Packages" tab -- staff
don't need wp-admin access for day-to-day work. Deleting a package (which
also deletes its usage log) stays wp-admin-only.

This is a separate, decoupled plugin from Karks CRM itself, on purpose --
the two evolve independently. Its own database tables, its own admin
screens, its own PDF report. It depends on Karks CRM being active (declared
via the `Requires Plugins` header) and touches only the following from
core, nothing else:

* `KCRM_CAPABILITY` -- the capability required to manage this add-on's screens too.
* `KCRM_Context::get_current_company_id()` -- which company's data to show.
* `KCRM_Customer`, `KCRM_Company`, `KCRM_Service` -- read access (`find()`/`find_many()`/`for_company()`, `KCRM_Customer::display_name()`) to pick a customer/service when creating a package and to label reports.
* `KCRM_Customer::jobs_for()` and the customer's `company_id`/`parent_customer_id` -- the front-end package form's Client/Job choice, and the check that a package is only saved for the client being viewed or one of its Jobs.
* `KCRM_Model_Base` -- the generic CRUD base this add-on's own models extend.
* `KCRM_Colors::get()` and `KCRM_Company::pdf_accent_color()` -- so the PDF report matches Karks CRM's own invoice PDF styling.
* `KCRM_PDF::logo_data_uri( $company )` -- reuses the exact same logo-rendering logic as the invoice PDF.
* `\Dompdf\Dompdf` -- via Karks CRM's already-loaded Composer autoloader; this plugin does not vendor its own copy.
* The `kcrm_customer_edit_after_sections` action -- renders the "Packages" summary box on the wp-admin customer edit screen (that screen has no tabs).
* The `kcrm_customer_profile_tabs` filter -- contributes a "Packages" tab (summary, usage log, "Log Usage" form, Add/Edit Package form, and Renew) to the front-end `/crm/` customer profile screen.
* The `kcrm_reports_views` filter -- contributes the "Package Usage" and "Upcoming Renewals" reports to the front-end Reports screen.
* `KCRM_Front::is_crm_page()`, `KCRM_Front::endpoint_url()` and `KCRM_Front::nav_nonce_args()` -- so the front-end usage and package submissions are recognized and redirect back to the right front-end customer URL, matching the front-end's own `.kcrm-front-table` / `.kcrm-front-form` styling.

It deliberately does not touch `KCRM_Controller_Base`, `KCRM_Admin_Screen_Trait`,
`KCRM_Invoice`, or `KCRM_Invoice_Item` -- that layer is more likely to change
as Karks CRM's own front-end evolves, and staying off it means changes there
can't break this add-on.

== Database ==

Two tables, `{prefix}kcrmpkg_packages` and `{prefix}kcrmpkg_package_usage`.
Hours remaining is always computed live (allotted minus the sum of logged
usage), never cached, matching Karks CRM's own precedent for balance-due
calculations.

Each package has a `package_type` (`hours` or `term`). Term packages link
to the package that renewed them through `renewed_by_id`.

== Changelog ==

= 1.1.0 =
* Added term-based packages for things like yearly web hosting. On the package form, choose "Term" instead of "Hours". A term package is defined by its start and end dates (the end date is required) and has no allotted hours.
* Term packages keep a notes-only "Activity Log" in wp-admin and on the front-end "Packages" tab. Notes are stored with no hours.
* A "Renews in N days" badge appears in the 30 days before a term package ends, and "Due for renewal" once the end date has passed.
* Added a "Renew" button to term packages. It creates the next term with the same length, client, service and price (e.g. 2026-01-01 – 2026-12-31 renews as 2027-01-01 – 2027-12-31). The current term stays active until its end date. A term can only be renewed once.
* Added an "Upcoming Renewals" report to the Karks CRM Reports screen, with 30/60/90-day, overdue and all filters, plus CSV export.
* The Package Usage report and the customer hours totals now cover hour-based packages only. The PDF for a term package is a "Service Term Summary" showing the term dates instead of hours.
* Packages can now be added and edited from the front-end "Packages" tab on a client's profile, without wp-admin access. Term packages can be renewed there too, including overdue ones in Package History. For clients with Jobs, the form lets you choose the client or one of its Jobs. Deleting a package stays wp-admin-only.
* wp-admin: on a client's package list, "Add Package" now pre-selects that client, and the add form links back to the client's packages. The Packages box on the wp-admin customer screen now has an "Add Package" button.
* wp-admin: rows in the Packages table are highlighted on hover, and clicking anywhere on a row opens that package. Ctrl/Cmd-click or middle-click opens it in a new tab. The Edit and Delete links work as before.
* The package form now rejects unknown statuses.
* Existing packages are unaffected: the database update (version 1.2.0) adds two columns and treats every existing package as hour-based.

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
