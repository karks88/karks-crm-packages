=== Karks CRM Packages ===
Contributors: karks88
Tags: crm, maintenance, hours, hosting, renewals
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Track prepaid maintenance hours and yearly hosting terms for Karks CRM clients, with usage logs, renewals, invoicing, and PDF reports.

== Description ==

Karks CRM Packages is an add-on for [Karks CRM](https://wordpress.org/plugins/karks-crm/). It helps you track the packages you sell to clients. That includes a block of prepaid hours (e.g. "$200 for 4 hours of maintenance") or a service that renews on a schedule, like a year of web hosting.

**Requires the free [Karks CRM](https://wordpress.org/plugins/karks-crm/) plugin.** WordPress will ask you to install and activate it first.

=== Features ===

* **Hour packages:** set the hours a client has paid for, log each piece of work against it (date, hours, description), and see the hours remaining at a glance.
* **Overage alerts:** a package that goes over its hours is flagged, so you know to bill the extra time.
* **Term packages:** for services defined by dates instead of hours, such as yearly hosting. Each keeps a notes-only activity log.
* **Renewal reminders:** a "Renews in N days" badge appears in the 30 days before a term ends, and "Due for renewal" once it has passed.
* **One-click renewals:** create the next term with the same length, client, service, and price.
* **Renew + Invoice:** renew a term and create an Open Karks CRM invoice for it in one step. The invoice type, tax rate, and line description are copied from the client's last invoice for that service.
* **Client-ready PDF reports:** a usage report for any package, styled to match your Karks CRM invoices.
* **Reports:** "Package Usage" (hours used across all packages) and "Upcoming Renewals" (terms ending in the next 30, 60, or 90 days), each with CSV export.
* **Works where you work:** manage packages from wp-admin or from a "Packages" tab on each client's front-end Karks CRM profile.
* **Included in backups:** packages and their logs travel with Karks CRM's company export/import and Karks CRM Backups.

=== Helpful Links ===

* [Official Website - karks-crm.com](https://karks-crm.com/)
* [Karks CRM on WordPress.org](https://wordpress.org/plugins/karks-crm/)

== Installation ==

1. Install and activate [Karks CRM](https://wordpress.org/plugins/karks-crm/), and add your first company.
2. Install Karks CRM Packages through the WordPress plugins screen, or upload it to `/wp-content/plugins/karks-crm-packages`, then activate it.
3. Open a client's profile in Karks CRM and choose the "Packages" tab. Alternatively, go to Karks CRM → Packages in wp-admin.

== Frequently Asked Questions ==

= Does this work without Karks CRM? =

No. It's an add-on, and it uses Karks CRM's clients, services, invoices, and PDF styling. If Karks CRM is deactivated, this plugin shows a notice and stays inactive until Karks CRM is back. Your package data is untouched.

= What's the difference between an hours package and a term package? =

An hours package is a prepaid block of time. You log work against it, and it tracks how many hours remain. A term package is defined by its start and end dates, like a year of hosting. It has no hours, but keeps a log of notes and can be renewed into the next term.

= How does "Renew + Invoice" decide what goes on the invoice? =

It looks for the client's most recent invoice for the same service and copies its invoice type, tax rate, and line description. If that description ends in "Expires:" followed by a date, the date is updated to the new term's. The amount is the package price. The invoice is dated the day before the new term starts (or today, if that has passed) and is due 14 days later. If there's no earlier invoice to copy from, it uses the service name and your company's default tax rate.

You can edit the invoice afterwards, like any other Karks CRM invoice.

= Who can see and manage packages? =

The same users who can access Karks CRM: Administrators and users with Karks CRM's CRM Manager role.

= Where is my data stored? =

In two database tables on your own site: `{prefix}kcrmpkg_packages` and `{prefix}kcrmpkg_package_usage`. Nothing is sent to any outside service.

= What happens to my data if I delete the plugin? =

It's kept. Deleting the plugin doesn't remove its tables, so reinstalling brings your packages back. To remove the data permanently, drop the two tables listed above.

== Screenshots ==

1. The "Packages" tab on a client's profile shows current packages, hours used, and hours remaining.
2. Log work against an hours package and see its full usage history.
3. Term packages show their dates, a renewal reminder, and the Renew and Renew + Invoice buttons.
4. A client-ready PDF usage report, styled to match your Karks CRM invoices.
5. The Package Usage report summarizes hours used across all packages.
6. The Upcoming Renewals report lists terms ending soon.

== Changelog ==

= 1.2.1 =
* Prepared for WordPress.org: the plugin's scripts and styles now load as separate files, only on its own screens, rather than being printed inline. No change in behavior.
* Added sample packages for the WordPress.org Live Preview. They're only created inside the preview, never on a real site.
* Rewrote the readme for users, and added an FAQ.

= 1.2.0 =
* Added "Renew + Invoice" for term packages, next to Renew. It renews the package and creates an Open Karks CRM invoice for the new term in one step.
* The invoice copies its type, tax rate, and line description from the client's most recent invoice for the same service. A trailing "Expires: <date>" in the description is updated to the new term. With no earlier invoice, it uses the service name and the company's default tax rate.
* The invoice is dated the day before the new term starts (or today, if that has passed) and is due 14 days later. The success message links straight to it.
* Plain "Renew" works as before and creates no invoice.

= 1.1.1 =
* Packages are now included in Karks CRM's company export/import, and so in backups made by Karks CRM Backups. That covers every package (hours and term), its usage entries and notes, and renewal links. Before this, exports and backups left packages out entirely. Make a fresh export or backup after updating: older ones don't contain packages.
* Importing as a new company recreates the packages against the new company's clients and services. Restoring a backup replaces the company's packages with the ones in the backup.
* Restoring an older backup made before this version no longer breaks existing packages. The restore recreates every client and service with new IDs, so existing packages are now relinked to them instead of being left pointing at clients that no longer exist. A package for a client added after that backup was made can't be relinked, since the restore removes that client.
* Requires a Karks CRM version with the `kcrm_company_export_data` / `kcrm_company_import_data` hooks (0.9.10.6 or later). On older versions, packages are simply left out of exports as before.

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
