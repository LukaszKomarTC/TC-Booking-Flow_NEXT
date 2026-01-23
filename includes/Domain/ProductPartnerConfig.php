<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Product Partner Configuration
 *
 * Handles Partner program enable/disable for WooCommerce product categories.
 * Controls whether the partner discount system is available for booking products.
 *
 * Configuration hierarchy:
 * 1. Product category term meta (tcbf_partners_enabled)
 * 2. Global option fallback (tcbf_product_partners_enabled_default)
 * 3. Default: enabled (true)
 */
class ProductPartnerConfig {

	/**
	 * Term meta key for partners enabled flag on product_cat taxonomy
	 */
	const TERM_META_PARTNERS_ENABLED = 'tcbf_partners_enabled';

	/**
	 * Option key for global default (fallback when no category setting)
	 */
	const OPTION_GLOBAL_PARTNERS_ENABLED = 'tcbf_product_partners_enabled_default';

	/**
	 * Cache for category configs
	 * @var array<int, bool>
	 */
	private static $category_cache = [];

	/**
	 * Check if partners are enabled for a product category
	 *
	 * @param int $term_id The product_cat term ID
	 * @return bool True if partners enabled, false if disabled
	 */
	public static function category_partners_enabled( int $term_id ) : bool {

		if ( $term_id <= 0 ) {
			return self::get_global_default();
		}

		if ( isset( self::$category_cache[ $term_id ] ) ) {
			return self::$category_cache[ $term_id ];
		}

		$meta_value = get_term_meta( $term_id, self::TERM_META_PARTNERS_ENABLED, true );

		// If no meta set, fall back to global default
		if ( $meta_value === '' ) {
			$enabled = self::get_global_default();
		} else {
			$val = strtolower( trim( (string) $meta_value ) );
			$enabled = in_array( $val, [ '1', 'yes', 'true', 'on' ], true );
		}

		self::$category_cache[ $term_id ] = $enabled;
		return $enabled;
	}

	/**
	 * Check if partners are enabled for a WooCommerce product
	 *
	 * Checks the product's categories. If ANY category has partners explicitly
	 * disabled, partners are disabled for the product. Otherwise enabled.
	 *
	 * @param int $product_id The WooCommerce product ID
	 * @return bool True if partners enabled, false if disabled
	 */
	public static function product_partners_enabled( int $product_id ) : bool {

		if ( $product_id <= 0 ) {
			return self::get_global_default();
		}

		// Get product categories
		$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return self::get_global_default();
		}

		// Check each category - if ANY has partners explicitly disabled, return false
		foreach ( $terms as $term_id ) {
			$meta_value = get_term_meta( (int) $term_id, self::TERM_META_PARTNERS_ENABLED, true );

			// Only check if explicitly set (not empty)
			if ( $meta_value !== '' ) {
				$val = strtolower( trim( (string) $meta_value ) );
				if ( in_array( $val, [ '0', 'no', 'false', 'off' ], true ) ) {
					return false;
				}
			}
		}

		// No category explicitly disabled partners, use global default
		return self::get_global_default();
	}

	/**
	 * Get global default for partners enabled
	 *
	 * @return bool Default is true (partners enabled)
	 */
	public static function get_global_default() : bool {
		$option = get_option( self::OPTION_GLOBAL_PARTNERS_ENABLED, '1' );
		$val = strtolower( trim( (string) $option ) );
		return in_array( $val, [ '1', 'yes', 'true', 'on', '' ], true );
	}

	/**
	 * Set partners enabled for a product category
	 *
	 * @param int $term_id The product_cat term ID
	 * @param bool $enabled Whether partners are enabled
	 * @return bool True on success
	 */
	public static function set_category_partners_enabled( int $term_id, bool $enabled ) : bool {
		if ( $term_id <= 0 ) {
			return false;
		}

		$value = $enabled ? '1' : '0';
		$result = update_term_meta( $term_id, self::TERM_META_PARTNERS_ENABLED, $value );

		// Clear cache
		unset( self::$category_cache[ $term_id ] );

		return $result !== false;
	}

	/**
	 * Remove partners setting from a category (revert to global default)
	 *
	 * @param int $term_id The product_cat term ID
	 * @return bool True on success
	 */
	public static function clear_category_partners_setting( int $term_id ) : bool {
		if ( $term_id <= 0 ) {
			return false;
		}

		$result = delete_term_meta( $term_id, self::TERM_META_PARTNERS_ENABLED );

		// Clear cache
		unset( self::$category_cache[ $term_id ] );

		return $result;
	}

	/**
	 * Set global default for partners enabled
	 *
	 * @param bool $enabled Whether partners are enabled by default
	 * @return bool True on success
	 */
	public static function set_global_default( bool $enabled ) : bool {
		return update_option( self::OPTION_GLOBAL_PARTNERS_ENABLED, $enabled ? '1' : '0' );
	}

	/**
	 * Get all product categories with their partner settings
	 *
	 * @return array Array of term data with partner enabled status
	 */
	public static function get_all_category_settings() : array {
		$terms = get_terms([
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		]);

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$result = [];
		foreach ( $terms as $term ) {
			$meta_value = get_term_meta( $term->term_id, self::TERM_META_PARTNERS_ENABLED, true );

			$result[] = [
				'term_id'  => $term->term_id,
				'name'     => $term->name,
				'slug'     => $term->slug,
				'setting'  => $meta_value, // '', '1', or '0'
				'enabled'  => self::category_partners_enabled( $term->term_id ),
			];
		}

		return $result;
	}

	/**
	 * Clear all caches
	 */
	public static function clear_cache() : void {
		self::$category_cache = [];
	}
}
