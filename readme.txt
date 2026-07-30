=== Karks CRM Packages ===
Requires Plugins: karks-crm
Requires at least: 6.5
Requires PHP: 7.4

Track maintenance-package hour allotments and usage for Karks CRM customers,
with a client-shareable PDF usage report.

== Description ==

Sells a flat-rate allotment of hours (e.g. "$200 for 4 hours")? This add-on
tracks the allotment, lets you log itemized usage against it (date, hours,
description), shows the running remaining balance, flags when a package goes
over its allotment (so you know to create a separate overage invoice in
Karks CRM), and generates a client-shareable PDF usage report.

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
* The `kcrm_customer_edit_after_sections` action -- the one hook Karks CRM exposes, used to render the "Packages" summary box on the customer edit screen (both wp-admin and the front-end `/crm/` screen).

It deliberately does not touch `KCRM_Controller_Base`, `KCRM_Admin_Screen_Trait`,
`KCRM_Invoice`, or `KCRM_Invoice_Item` -- that layer is more likely to change
as Karks CRM's own front-end evolves, and staying off it means changes there
can't break this add-on.

== Database ==

Two tables, `{prefix}kcrmpkg_packages` and `{prefix}kcrmpkg_package_usage`.
Hours remaining is always computed live (allotted minus the sum of logged
usage), never cached, matching Karks CRM's own precedent for balance-due
calculations.
