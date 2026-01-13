<?php
/**
 * Plugin Name: TC — Booking Flow NEXT (Refactored Architecture)
 * Description: Consolidates GF44 → Woo cart/order booking flow and Early Booking Discount snapshot. Supports optional split of participation vs rental and per-event EB scope toggles.
 * Version: 0.3.41-alpha
 * Text Domain: tc-booking-flow-next
 * Author: Tossa Cycling (internal)
 */

if ( ! defined('ABSPATH') ) exit;

if ( ! defined('TC_BF_VERSION') ) define('TC_BF_VERSION','0.3.41-alpha');
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

add_action('plugins_loaded', function () {
	load_plugin_textdomain( TC_BF_TEXTDOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	\TC_BF\Plugin::instance();
	\TC_BF\Sc_Event_Extras::init();
	\TC_BF\Partner_Portal::init();

	// TCBF-11: Initialize consolidated event meta box
	if ( is_admin() ) {
		\TC_BF\Admin\Admin_Event_Meta::init();
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
