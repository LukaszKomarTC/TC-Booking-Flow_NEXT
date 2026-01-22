<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Product EB Configuration
 *
 * Handles Early Booking discount rules for WooCommerce product categories.
 * Rules are stored as term meta on product_cat taxonomy.
 *
 * This enables EB discounts for WooCommerce Bookings products (rentals)
 * independent of sc_event posts.
 */
class ProductEBConfig {

	/**
	 * Term meta key for EB enabled flag
	 */
	const TERM_META_EB_ENABLED = 'tcbf_eb_enabled';

	/**
	 * Term meta key for EB rules JSON
	 */
	const TERM_META_EB_RULES_JSON = 'tcbf_eb_rules_json';

	/**
	 * Option key for global product EB rules (fallback when no category rules)
	 */
	const OPTION_GLOBAL_EB_RULES = 'tcbf_product_eb_rules_global';

	/**
	 * Cache for category configs
	 * @var array
	 */
	private static $category_cache = [];

	/**
	 * Get EB configuration for a product category
	 *
	 * @param int $term_id The product_cat term ID
	 * @return array Configuration array with enabled, version, global_cap, and steps
	 */
	public static function get_category_config( int $term_id ) : array {

		if ( $term_id <= 0 ) {
			return self::get_default_config();
		}

		if ( isset( self::$category_cache[ $term_id ] ) ) {
			return self::$category_cache[ $term_id ];
		}

		$cfg = self::get_default_config();

		// Check if EB is enabled for this category
		$enabled = get_term_meta( $term_id, self::TERM_META_EB_ENABLED, true );
		if ( $enabled !== '' ) {
			$val = strtolower( trim( (string) $enabled ) );
			$cfg['enabled'] = in_array( $val, [ '1', 'yes', 'true', 'on' ], true );
		}

		// Get rules JSON
		$rules_json = (string) get_term_meta( $term_id, self::TERM_META_EB_RULES_JSON, true );
		if ( $rules_json !== '' ) {
			$decoded = json_decode( $rules_json, true );
			if ( is_array( $decoded ) ) {
				$cfg = self::parse_rules_json( $decoded, $cfg );
			}
		}

		self::$category_cache[ $term_id ] = $cfg;
		return $cfg;
	}

	/**
	 * Get EB configuration for a WooCommerce product
	 *
	 * Looks up the product's categories and returns the first matching EB config.
	 * Falls back to global product EB rules if no category has rules.
	 *
	 * @param int $product_id The WooCommerce product ID
	 * @return array Configuration array with enabled, version, global_cap, steps, and source_category_id
	 */
	public static function get_product_config( int $product_id ) : array {

		if ( $product_id <= 0 ) {
			return self::get_global_config();
		}

		// Get product categories
		$terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return self::get_global_config();
		}

		// Check each category for EB rules (first enabled wins)
		foreach ( $terms as $term_id ) {
			$cfg = self::get_category_config( (int) $term_id );
			if ( ! empty( $cfg['enabled'] ) && ! empty( $cfg['steps'] ) ) {
				$cfg['source_category_id'] = (int) $term_id;
				return $cfg;
			}
		}

		// Fallback to global product EB rules
		return self::get_global_config();
	}

	/**
	 * Get global product EB configuration (fallback)
	 *
	 * @return array Configuration array
	 */
	public static function get_global_config() : array {
		$cfg = self::get_default_config();

		$rules_json = get_option( self::OPTION_GLOBAL_EB_RULES, '' );
		if ( $rules_json !== '' ) {
			$decoded = json_decode( $rules_json, true );
			if ( is_array( $decoded ) ) {
				$cfg = self::parse_rules_json( $decoded, $cfg );
			}
		}

		$cfg['source_category_id'] = 0; // global
		return $cfg;
	}

	/**
	 * Save EB configuration for a product category
	 *
	 * @param int $term_id The product_cat term ID
	 * @param array $config Configuration array with enabled and rules
	 * @return bool True on success
	 */
	public static function save_category_config( int $term_id, array $config ) : bool {
		if ( $term_id <= 0 ) {
			return false;
		}

		// Save enabled flag
		$enabled = ! empty( $config['enabled'] ) ? '1' : '0';
		update_term_meta( $term_id, self::TERM_META_EB_ENABLED, $enabled );

		// Save rules JSON
		$rules = [
			'version'    => 1,
			'global_cap' => $config['global_cap'] ?? [ 'enabled' => false, 'amount' => 0 ],
			'steps'      => $config['steps'] ?? [],
		];
		update_term_meta( $term_id, self::TERM_META_EB_RULES_JSON, wp_json_encode( $rules ) );

		// Clear cache
		unset( self::$category_cache[ $term_id ] );

		return true;
	}

	/**
	 * Save global product EB configuration
	 *
	 * @param array $config Configuration array
	 * @return bool True on success
	 */
	public static function save_global_config( array $config ) : bool {
		$rules = [
			'version'    => 1,
			'enabled'    => ! empty( $config['enabled'] ),
			'global_cap' => $config['global_cap'] ?? [ 'enabled' => false, 'amount' => 0 ],
			'steps'      => $config['steps'] ?? [],
		];
		return update_option( self::OPTION_GLOBAL_EB_RULES, wp_json_encode( $rules ) );
	}

	/**
	 * Get default configuration structure
	 *
	 * @return array
	 */
	private static function get_default_config() : array {
		return [
			'enabled'            => false,
			'version'            => 1,
			'global_cap'         => [ 'enabled' => false, 'amount' => 0.0 ],
			'steps'              => [],
			'source_category_id' => null,
		];
	}

	/**
	 * Parse rules JSON into configuration array
	 *
	 * @param array $decoded Decoded JSON data
	 * @param array $cfg Base configuration to merge into
	 * @return array Updated configuration
	 */
	private static function parse_rules_json( array $decoded, array $cfg ) : array {

		// Check enabled flag in JSON (for global config)
		if ( isset( $decoded['enabled'] ) ) {
			$cfg['enabled'] = ! empty( $decoded['enabled'] );
		}

		// Schema v1: object with steps
		if ( isset( $decoded['steps'] ) && is_array( $decoded['steps'] ) ) {
			$cfg['version'] = isset( $decoded['version'] ) ? (int) $decoded['version'] : 1;

			if ( isset( $decoded['global_cap'] ) && is_array( $decoded['global_cap'] ) ) {
				$cfg['global_cap']['enabled'] = ! empty( $decoded['global_cap']['enabled'] );
				$cfg['global_cap']['amount']  = isset( $decoded['global_cap']['amount'] )
					? (float) $decoded['global_cap']['amount']
					: 0.0;
			}

			$steps = [];
			foreach ( $decoded['steps'] as $s ) {
				if ( ! is_array( $s ) ) continue;

				$min   = isset( $s['min_days_before'] ) ? (int) $s['min_days_before'] : 0;
				$type  = isset( $s['type'] ) ? strtolower( (string) $s['type'] ) : 'percent';
				$value = isset( $s['value'] ) ? (float) $s['value'] : 0.0;

				if ( $min < 0 || $value <= 0 ) continue;
				if ( ! in_array( $type, [ 'percent', 'fixed' ], true ) ) {
					$type = 'percent';
				}

				$cap_s = [ 'enabled' => false, 'amount' => 0.0 ];
				if ( isset( $s['cap'] ) && is_array( $s['cap'] ) ) {
					$cap_s['enabled'] = ! empty( $s['cap']['enabled'] );
					$cap_s['amount']  = isset( $s['cap']['amount'] ) ? (float) $s['cap']['amount'] : 0.0;
				}

				$steps[] = [
					'min_days_before' => $min,
					'type'            => $type,
					'value'           => $value,
					'cap'             => $cap_s,
				];
			}

			// Sort steps descending by min_days_before
			usort( $steps, function( $a, $b ) {
				return ( (int) $b['min_days_before'] ) <=> ( (int) $a['min_days_before'] );
			});

			$cfg['steps'] = $steps;
		}

		return $cfg;
	}

	/**
	 * Get all product categories with EB rules
	 *
	 * @return array Array of term objects with EB config
	 */
	public static function get_categories_with_eb_rules() : array {
		$terms = get_terms([
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'meta_query' => [
				[
					'key'     => self::TERM_META_EB_ENABLED,
					'value'   => '1',
					'compare' => '=',
				],
			],
		]);

		if ( is_wp_error( $terms ) ) {
			return [];
		}

		$result = [];
		foreach ( $terms as $term ) {
			$cfg = self::get_category_config( $term->term_id );
			$result[] = [
				'term'   => $term,
				'config' => $cfg,
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
