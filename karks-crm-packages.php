<?php
/**
 * Plugin Name: Karks CRM Packages
 * Description: Track maintenance-package hour allotments and usage for Karks CRM customers, with a client-shareable PDF usage report.
 * Version: 1.0.2
 * Author: Eric Karkovack
 * Author URI: https://karks.com
 * Text Domain: karks-crm-packages
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: karks-crm
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KCRMPKG_VERSION', '1.0.2' );
define( 'KCRMPKG_DB_VERSION', '1.1.0' );
define( 'KCRMPKG_PLUGIN_FILE', __FILE__ );
define( 'KCRMPKG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KCRMPKG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Safe to load unconditionally at the top level: neither file declares a
// class that extends/references a karks-crm symbol, so load order relative
// to karks-crm's own main file doesn't matter for these two.
require_once KCRMPKG_PLUGIN_DIR . 'includes/class-kcrmpkg-db.php';
require_once KCRMPKG_PLUGIN_DIR . 'includes/class-kcrmpkg-activator.php';

register_activation_hook( __FILE__, array( 'KCRM_Pkg_Activator', 'activate' ) );

/**
 * Boot the add-on. Everything below requires karks-crm's own classes
 * (KCRM_Model_Base, KCRM_Customer, etc.) to already be defined, so it's all
 * deferred to plugins_loaded rather than top-level requires.
 *
 * This isn't just an ordering nicety: the `Requires Plugins` header above
 * only gates *activation*, it does not reorder WordPress's own plugin
 * file-include sequence -- and that sequence is NOT alphabetical by plugin
 * name the way you'd expect ("karks-crm-packages/..." sorts before
 * "karks-crm/karks-crm.php" as a string, since '-' < '/'), so this add-on's
 * main file can easily be included by WordPress before karks-crm's own.
 * plugins_loaded only fires after every active plugin's main file has been
 * included, though, so anything deferred this far is guaranteed safe
 * regardless of that include order.
 *
 * The class_exists() guard is a second, independent safety net for a
 * different case entirely: karks-crm being deactivated later while this
 * add-on stays active.
 */
function kcrmpkg_run() {
	if ( ! class_exists( 'KCRM_Customer' ) ) {
		add_action( 'admin_notices', 'kcrmpkg_missing_karks_crm_notice' );
		return;
	}

	require_once KCRMPKG_PLUGIN_DIR . 'includes/models/class-kcrmpkg-package.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/models/class-kcrmpkg-usage.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/controllers/class-kcrmpkg-controller-base.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/admin/class-kcrmpkg-admin-packages.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/front/class-kcrmpkg-front-usage.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/integration/class-kcrmpkg-customer-section.php';
	require_once KCRMPKG_PLUGIN_DIR . 'includes/pdf/class-kcrmpkg-pdf.php';

	add_action( 'init', array( 'KCRM_Pkg_Activator', 'maybe_upgrade' ), 20 );

	$admin_packages = new KCRM_Pkg_Admin_Packages();
	// Priority 20: add_submenu_page() needs karks-crm's own add_menu_page('karks-crm', ...)
	// call to have already run in this same admin_menu firing, so it can resolve the
	// correct hookname prefix for our submenu. Both plugins boot via plugins_loaded at
	// the same default priority, and WordPress doesn't guarantee which plugin's
	// plugins_loaded callback (and therefore admin_menu registration) runs first --
	// running ours later here removes the dependency on that ordering entirely.
	add_action( 'admin_menu', array( $admin_packages, 'register_menu' ), 20 );
	add_action( 'admin_init', array( $admin_packages, 'handle_actions' ) );
	add_action( 'admin_post_kcrmpkg_download_package_pdf', array( $admin_packages, 'handle_pdf_download' ) );

	$customer_section = new KCRM_Pkg_Customer_Section();
	add_action( 'kcrm_customer_edit_after_sections', array( $customer_section, 'render' ), 10, 2 );
	add_filter( 'kcrm_customer_profile_tabs', array( $customer_section, 'register_tab' ), 10, 3 );

	$front_usage = new KCRM_Pkg_Front_Usage();
	add_action( 'template_redirect', array( $front_usage, 'handle_actions' ) );
}
add_action( 'plugins_loaded', 'kcrmpkg_run' );

function kcrmpkg_missing_karks_crm_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Karks CRM Packages requires the Karks CRM plugin to be active.', 'karks-crm-packages' ) . '</p></div>';
}
