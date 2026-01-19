<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Template Loader for WooCommerce template overrides.
 *
 * Provides plugin-level template overrides with escape hatches:
 * - Theme templates always win (standard WooCommerce behavior)
 * - Can be disabled entirely via TCBF_DISABLE_TEMPLATE_OVERRIDES constant
 *
 * Only overrides specific templates needed for TCBF functionality:
 * - woocommerce/order/order-details.php (grouped order items)
 * - woocommerce-bookings/order/booking-summary-list.php (clean fallback)
 * - woocommerce-bookings/order/booking-display.php (suppress default)
 *
 * @package TC_Booking_Flow
 */
final class Template_Loader {

	/**
	 * Exact templates we override (relative paths).
	 *
	 * @var array<string, string> Template name => plugin subfolder
	 */
	private const TEMPLATE_MAP = [
		// WooCommerce core templates
		'order/order-details.php' => 'woocommerce',

		// WooCommerce Bookings templates
		'order/booking-summary-list.php' => 'woocommerce-bookings',
		'order/booking-display.php'      => 'woocommerce-bookings',
	];

	/**
	 * Initialize template loader hooks.
	 */
	public static function init() : void {
		// Escape hatch: constant to disable all template overrides
		if ( defined( 'TCBF_DISABLE_TEMPLATE_OVERRIDES' ) && TCBF_DISABLE_TEMPLATE_OVERRIDES ) {
			return;
		}

		// WooCommerce core template filter
		add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_wc_template' ], 10, 3 );

		// WooCommerce Bookings template filter
		add_filter( 'woocommerce_locate_template', [ __CLASS__, 'locate_bookings_template' ], 10, 3 );
	}

	/**
	 * Locate WooCommerce core templates.
	 *
	 * Theme templates always win (checked by WooCommerce before this filter).
	 * We only provide fallback if no theme override exists.
	 *
	 * @param string $template      Full template path found by WooCommerce.
	 * @param string $template_name Template name (e.g., 'order/order-details.php').
	 * @param string $template_path Template path prefix (usually 'woocommerce/').
	 * @return string Template path (ours or original).
	 */
	public static function locate_wc_template( string $template, string $template_name, string $template_path ) : string {
		// Only process templates in our map for WooCommerce
		if ( ! isset( self::TEMPLATE_MAP[ $template_name ] ) ) {
			return $template;
		}

		// Only handle woocommerce templates here
		if ( self::TEMPLATE_MAP[ $template_name ] !== 'woocommerce' ) {
			return $template;
		}

		// Check if theme has an override (theme always wins)
		if ( self::theme_has_template( $template_name, 'woocommerce' ) ) {
			return $template;
		}

		// Use our plugin template
		$plugin_template = TC_BF_PATH . 'templates/woocommerce/' . $template_name;
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Locate WooCommerce Bookings templates.
	 *
	 * @param string $template      Full template path.
	 * @param string $template_name Template name.
	 * @param string $template_path Template path prefix.
	 * @return string Template path.
	 */
	public static function locate_bookings_template( string $template, string $template_name, string $template_path ) : string {
		// Only process templates in our map for Bookings
		if ( ! isset( self::TEMPLATE_MAP[ $template_name ] ) ) {
			return $template;
		}

		// Only handle woocommerce-bookings templates here
		if ( self::TEMPLATE_MAP[ $template_name ] !== 'woocommerce-bookings' ) {
			return $template;
		}

		// Check if theme has an override (theme always wins)
		if ( self::theme_has_template( $template_name, 'woocommerce-bookings' ) ) {
			return $template;
		}

		// Use our plugin template
		$plugin_template = TC_BF_PATH . 'templates/woocommerce-bookings/' . $template_name;
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}

		return $template;
	}

	/**
	 * Check if theme has a template override.
	 *
	 * Checks both child theme and parent theme locations.
	 *
	 * @param string $template_name Template name (e.g., 'order/order-details.php').
	 * @param string $prefix        Template prefix (e.g., 'woocommerce').
	 * @return bool True if theme has override.
	 */
	private static function theme_has_template( string $template_name, string $prefix ) : bool {
		// Check child theme first
		$child_theme_path = get_stylesheet_directory() . '/' . $prefix . '/' . $template_name;
		if ( file_exists( $child_theme_path ) ) {
			return true;
		}

		// Check parent theme
		$parent_theme_path = get_template_directory() . '/' . $prefix . '/' . $template_name;
		if ( file_exists( $parent_theme_path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get list of templates this plugin overrides.
	 *
	 * Useful for debugging or admin display.
	 *
	 * @return array<string, string> Template name => plugin folder.
	 */
	public static function get_template_map() : array {
		return self::TEMPLATE_MAP;
	}

	/**
	 * Check if template overrides are enabled.
	 *
	 * @return bool True if enabled.
	 */
	public static function is_enabled() : bool {
		if ( defined( 'TCBF_DISABLE_TEMPLATE_OVERRIDES' ) && TCBF_DISABLE_TEMPLATE_OVERRIDES ) {
			return false;
		}
		return true;
	}
}
