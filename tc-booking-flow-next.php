<?php
/**
 * Plugin Name: TC — Booking Flow NEXT (Refactored Architecture)
 * Description: Consolidates GF44 → Woo cart/order booking flow and Early Booking Discount snapshot. Supports optional split of participation vs rental and per-event EB scope toggles.
 * Version: 0.8.17
 * Text Domain: tc-booking-flow-next
 * Author: Tossa Cycling (internal)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('TC_BF_VERSION') ) define('TC_BF_VERSION','0.8.17');
if ( ! defined('TC_BF_PATH') ) define('TC_BF_PATH', plugin_dir_path(__FILE__));
if ( ! defined('TC_BF_URL') ) define('TC_BF_URL', plugin_dir_url(__FILE__));

// i18n
if ( ! defined('TC_BF_TEXTDOMAIN') ) define('TC_BF_TEXTDOMAIN', 'tc-booking-flow-next');

// Initialize plugin update checker (GitHub releases)
require_once TC_BF_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$tcBfUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/LukaszKomarTC/TC-Booking-Flow_NEXT/',
	__FILE__,
	'tc-booking-flow-next'
);
$tcBfUpdateChecker->getVcsApi()->enableReleaseAssets();

// REST API endpoint for remote update trigger
add_action( 'rest_api_init', function() {
	register_rest_route( 'tc-booking-flow/v1', '/refresh', array(
		'methods'  => 'POST',
		'callback' => 'tc_bf_force_refresh',
		'permission_callback' => function( $request ) {
			// Allow authenticated admin users
			if ( current_user_can( 'update_plugins' ) ) {
				return true;
			}
			// Allow token-based authentication for external triggers (GitHub Actions)
			$token = $request->get_header( 'X-Update-Token' );
			if ( ! $token ) {
				$token = $request->get_param( 'token' );
			}
			if ( $token && defined( 'TC_BF_UPDATE_TOKEN' ) && hash_equals( TC_BF_UPDATE_TOKEN, $token ) ) {
				return true;
			}
			return false;
		},
	));
});

/**
 * Force refresh plugin update cache and optionally auto-update.
 *
 * @param WP_REST_Request $request
 * @return array
 */
function tc_bf_force_refresh( $request ) {
	global $wpdb, $tcBfUpdateChecker;

	// Clear all update caches aggressively
	delete_site_transient('update_plugins');
	wp_clean_plugins_cache();
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%puc%'");
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%tc_bf%update%'");
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient%update%'");

	// Force PUC to check GitHub for new version NOW
	if ( $tcBfUpdateChecker ) {
		$tcBfUpdateChecker->checkForUpdates();
	}

	// Force WordPress to check for updates
	wp_update_plugins();

	// Clear object cache
	wp_cache_flush();

	$result = [
		'status'  => 'refreshed',
		'version' => TC_BF_VERSION,
		'time'    => current_time('mysql')
	];

	// Auto-update if requested
	if ( $request->get_param('auto_update') ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$plugin_file = plugin_basename( TC_BF_PATH . 'tc-booking-flow-next.php' );
		$skin = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		// Get fresh update info
		$update_plugins = get_site_transient('update_plugins');

		// Debug info
		$result['plugin_file'] = $plugin_file;
		$result['available_updates'] = array_keys( (array) ($update_plugins->response ?? []) );

		if ( isset( $update_plugins->response[ $plugin_file ] ) ) {
			// Perform the upgrade
			$upgrade_result = $upgrader->upgrade( $plugin_file );

			$result['upgrade_attempted'] = true;
			$result['upgrade_result'] = $upgrade_result;
			$result['upgrade_errors'] = $skin->get_errors();

			// Re-activate plugin if needed
			if ( $upgrade_result && ! is_plugin_active( $plugin_file ) ) {
				activate_plugin( $plugin_file );
				$result['reactivated'] = true;
			}

			// Get new version after upgrade
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file );
			$result['new_version'] = $plugin_data['Version'];
			$result['updated'] = true;
		} else {
			$result['upgrade_attempted'] = false;
			$result['updated'] = false;
			$result['message'] = 'No update available or plugin already up to date';
		}
	}

	return $result;
}

require_once TC_BF_PATH . 'includes/admin/class-tc-bf-admin-product-meta.php';
require_once TC_BF_PATH . 'includes/admin/class-tc-bf-admin-settings.php';
require_once TC_BF_PATH . 'includes/admin/class-tc-bf-admin-partners.php';
require_once TC_BF_PATH . 'includes/admin/class-tc-bf-admin-event-eb.php';
require_once TC_BF_PATH . 'includes/Plugin.php';
require_once TC_BF_PATH . 'includes/class-tc-bf-sc-event-extras.php';
require_once TC_BF_PATH . 'includes/sc-event-template-functions.php';
require_once TC_BF_PATH . 'includes/class-tc-bf-partner-portal.php';

// TCBF-11: Event Meta Consolidation (canonical schema + mirror-write)
require_once TC_BF_PATH . 'includes/Domain/EventMeta.php';
require_once TC_BF_PATH . 'includes/Admin/Admin_Event_Meta.php';

// TCBF-13: Product EB Configuration (category-based EB rules for booking products)
require_once TC_BF_PATH . 'includes/Domain/ProductEBConfig.php';
require_once TC_BF_PATH . 'includes/Domain/BookingLedger.php';
require_once TC_BF_PATH . 'includes/Admin/Admin_Product_Category_EB.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo_BookingLedger.php';

// TCBF-14: Product Partner Configuration (category-based partner enable/disable)
require_once TC_BF_PATH . 'includes/Domain/ProductPartnerConfig.php';
require_once TC_BF_PATH . 'includes/Admin/Admin_Product_Category_Partner.php';

// TCBF-14: GF Semantic Field Mapping (inputName-based field resolution)
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_FieldMap.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_SemanticFields.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_FormValidator.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_BookingPartnerSelect.php';

add_action('plugins_loaded', function () {
	// Load translations using absolute path (more reliable across different folder names)
	$locale = determine_locale();
	$mofile = TC_BF_PATH . 'languages/' . TC_BF_TEXTDOMAIN . '-' . $locale . '.mo';
	load_textdomain( TC_BF_TEXTDOMAIN, $mofile );
	\TC_BF\Plugin::instance();
	\TC_BF\Sc_Event_Extras::init();
	\TC_BF\Partner_Portal::init();

	// TCBF-13: Initialize booking product ledger integration
	\TC_BF\Integrations\WooCommerce\Woo_BookingLedger::init();

	// TCBF-14: Register GF_FieldMap cache invalidation hooks
	\TC_BF\Integrations\GravityForms\GF_FieldMap::register_cache_invalidation_hooks();

	// TCBF-14: Initialize booking partner select (for booking product form)
	\TC_BF\Integrations\GravityForms\GF_BookingPartnerSelect::init();

	// TCBF-11: Initialize consolidated event meta box
	// TCBF-13: Initialize product category EB settings
	// TCBF-14: Initialize form validator for admin notices
	// TCBF-14: Initialize product category Partner settings
	if ( is_admin() ) {
		\TC_BF\Admin\Admin_Event_Meta::init();
		\TC_BF\Admin\Admin_Product_Category_EB::init();
		\TC_BF\Admin\Admin_Product_Category_Partner::init();
		\TC_BF\Integrations\GravityForms\GF_FormValidator::init();
	}
});

register_activation_hook(__FILE__, function(){
	// Ensure endpoint rewrite rules are registered.
	if ( class_exists('TC_BF\\Partner_Portal') ) {
		\TC_BF\Partner_Portal::add_endpoint();
	}
	flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function(){
	flush_rewrite_rules();
});
