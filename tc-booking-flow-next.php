<?php
/**
 * Plugin Name: TC — Booking Flow NEXT (Refactored Architecture)
 * Description: Consolidates GF44 → Woo cart/order booking flow and Early Booking Discount snapshot. Supports optional split of participation vs rental and per-event EB scope toggles.
 * Version: 0.7.41
 * Text Domain: tc-booking-flow-next
 * Author: Tossa Cycling (internal)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('TC_BF_VERSION') ) define('TC_BF_VERSION','0.7.41');
if ( ! defined('TC_BF_PATH') ) define('TC_BF_PATH', plugin_dir_path(__FILE__));
if ( ! defined('TC_BF_URL') ) define('TC_BF_URL', plugin_dir_url(__FILE__));

// i18n
if ( ! defined('TC_BF_TEXTDOMAIN') ) define('TC_BF_TEXTDOMAIN', 'tc-booking-flow-next');

// Initialize plugin update checker
require_once TC_BF_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$tcBfUpdateChecker = PucFactory::buildUpdateChecker(
	'https://staging.lukaszkomar.com/dev/tc-booking-flow-next/latest.json',
	__FILE__,
	'tc-booking-flow-next'
);

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
