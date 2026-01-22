<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Booking Ledger
 *
 * Early Booking (EB) and partner discount calculation for WooCommerce Booking products.
 * Works with cart items from WooCommerce Bookings + Gravity Forms Product Add-ons.
 *
 * This is the booking product equivalent of the event-based Ledger class.
 */
class BookingLedger {

	/**
	 * Cache for calculations
	 * @var array
	 */
	private static $calc_cache = [];

	/**
	 * Calculate ledger for a booking cart item
	 *
	 * @param array $cart_item WooCommerce cart item with booking data
	 * @return array Ledger calculation result
	 */
	public static function calculate_for_cart_item( array $cart_item ) : array {

		$cache_key = $cart_item['key'] ?? md5( serialize( $cart_item ) );
		if ( isset( self::$calc_cache[ $cache_key ] ) ) {
			return self::$calc_cache[ $cache_key ];
		}

		$result = self::get_default_result();

		// Extract booking data
		$booking = $cart_item['booking'] ?? [];
		if ( empty( $booking ) ) {
			return self::$calc_cache[ $cache_key ] = $result;
		}

		// Get product ID
		$product_id = (int) ( $cart_item['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return self::$calc_cache[ $cache_key ] = $result;
		}

		// Get base price from booking cost
		$base_price = (float) ( $booking['_cost'] ?? 0 );
		if ( $base_price <= 0 ) {
			return self::$calc_cache[ $cache_key ] = $result;
		}

		$result['base_price'] = $base_price;
		$result['product_id'] = $product_id;

		// Get booking start timestamp
		$start_ts = (int) ( $booking['_start_date'] ?? 0 );
		if ( $start_ts <= 0 ) {
			// Try to parse from date string
			$date_str = $booking['date'] ?? $booking['_date'] ?? '';
			if ( $date_str ) {
				$start_ts = strtotime( $date_str );
			}
		}
		$result['booking_start_ts'] = $start_ts;

		// Calculate days before booking
		$now_ts = (int) current_time( 'timestamp' );
		$days_before = 0;
		if ( $start_ts > 0 ) {
			$days_before = (int) floor( ( $start_ts - $now_ts ) / DAY_IN_SECONDS );
			if ( $days_before < 0 ) {
				$days_before = 0;
			}
		}
		$result['days_before'] = $days_before;

		// Get EB configuration for product
		$eb_cfg = ProductEBConfig::get_product_config( $product_id );
		$result['eb_config'] = $eb_cfg;
		$result['eb_source_category_id'] = $eb_cfg['source_category_id'] ?? 0;

		// Calculate EB discount
		if ( ! empty( $eb_cfg['enabled'] ) && ! empty( $eb_cfg['steps'] ) ) {
			$step = Ledger::select_eb_step( $days_before, $eb_cfg['steps'] );
			if ( $step ) {
				$result['eb_eligible'] = true;
				$result['eb_step'] = $step;

				$eb_calc = Ledger::compute_eb_amount( $base_price, $step, $eb_cfg['global_cap'] ?? [] );
				$result['eb_discount_amount'] = $eb_calc['amount'];
				$result['eb_discount_pct']    = $eb_calc['effective_pct'];
			}
		}

		// Get partner context
		$partner_ctx = self::resolve_partner_context( $cart_item );
		$result['partner'] = $partner_ctx;

		// Calculate partner discount (applied after EB)
		$after_eb = $base_price - $result['eb_discount_amount'];
		if ( ! empty( $partner_ctx['active'] ) && $partner_ctx['discount_pct'] > 0 ) {
			$partner_discount = $after_eb * ( $partner_ctx['discount_pct'] / 100 );
			$result['partner_discount_amount'] = round( $partner_discount, 2 );
		}

		// Calculate totals
		$total_discount = $result['eb_discount_amount'] + $result['partner_discount_amount'];
		$result['total_discount'] = round( $total_discount, 2 );
		$result['total_client']   = round( $base_price - $total_discount, 2 );

		// Calculate partner commission (based on client total)
		if ( ! empty( $partner_ctx['active'] ) && $partner_ctx['commission_pct'] > 0 ) {
			$result['partner_commission'] = round(
				$result['total_client'] * ( $partner_ctx['commission_pct'] / 100 ),
				2
			);
		}

		return self::$calc_cache[ $cache_key ] = $result;
	}

	/**
	 * Calculate ledger from booking parameters (for pre-cart calculation)
	 *
	 * @param int   $product_id     WooCommerce product ID
	 * @param float $base_price     Base price from WC Bookings
	 * @param int   $start_ts       Booking start timestamp
	 * @param array $partner_ctx    Optional pre-resolved partner context
	 * @return array Ledger calculation result
	 */
	public static function calculate_for_booking(
		int $product_id,
		float $base_price,
		int $start_ts,
		array $partner_ctx = []
	) : array {

		$result = self::get_default_result();
		$result['product_id']       = $product_id;
		$result['base_price']       = $base_price;
		$result['booking_start_ts'] = $start_ts;

		if ( $base_price <= 0 ) {
			return $result;
		}

		// Calculate days before booking
		$now_ts = (int) current_time( 'timestamp' );
		$days_before = 0;
		if ( $start_ts > 0 ) {
			$days_before = (int) floor( ( $start_ts - $now_ts ) / DAY_IN_SECONDS );
			if ( $days_before < 0 ) {
				$days_before = 0;
			}
		}
		$result['days_before'] = $days_before;

		// Get EB configuration for product
		$eb_cfg = ProductEBConfig::get_product_config( $product_id );
		$result['eb_config'] = $eb_cfg;
		$result['eb_source_category_id'] = $eb_cfg['source_category_id'] ?? 0;

		// Calculate EB discount
		if ( ! empty( $eb_cfg['enabled'] ) && ! empty( $eb_cfg['steps'] ) ) {
			$step = Ledger::select_eb_step( $days_before, $eb_cfg['steps'] );
			if ( $step ) {
				$result['eb_eligible'] = true;
				$result['eb_step'] = $step;

				$eb_calc = Ledger::compute_eb_amount( $base_price, $step, $eb_cfg['global_cap'] ?? [] );
				$result['eb_discount_amount'] = $eb_calc['amount'];
				$result['eb_discount_pct']    = $eb_calc['effective_pct'];
			}
		}

		// Use provided partner context or resolve
		if ( empty( $partner_ctx ) ) {
			$partner_ctx = PartnerResolver::resolve_partner_context( 0 ); // form_id 0 = any
		}
		$result['partner'] = $partner_ctx;

		// Calculate partner discount (applied after EB)
		$after_eb = $base_price - $result['eb_discount_amount'];
		if ( ! empty( $partner_ctx['active'] ) && $partner_ctx['discount_pct'] > 0 ) {
			$partner_discount = $after_eb * ( $partner_ctx['discount_pct'] / 100 );
			$result['partner_discount_amount'] = round( $partner_discount, 2 );
		}

		// Calculate totals
		$total_discount = $result['eb_discount_amount'] + $result['partner_discount_amount'];
		$result['total_discount'] = round( $total_discount, 2 );
		$result['total_client']   = round( $base_price - $total_discount, 2 );

		// Calculate partner commission (based on client total)
		if ( ! empty( $partner_ctx['active'] ) && $partner_ctx['commission_pct'] > 0 ) {
			$result['partner_commission'] = round(
				$result['total_client'] * ( $partner_ctx['commission_pct'] / 100 ),
				2
			);
		}

		return $result;
	}

	/**
	 * Resolve partner context from cart item GF lead data
	 *
	 * @param array $cart_item Cart item with _gravity_form_lead
	 * @return array Partner context
	 */
	private static function resolve_partner_context( array $cart_item ) : array {

		$lead = $cart_item['_gravity_form_lead'] ?? [];
		$form_id = (int) ( $lead['form_id'] ?? 0 );

		// Try to get partner override code from GF lead (field 24 for Form 45)
		$override_code = '';
		if ( $form_id === 45 ) {
			$override_code = trim( (string) ( $lead['24'] ?? '' ) );
		}

		// If admin override provided, use PartnerResolver with that code
		if ( $override_code !== '' && current_user_can( 'administrator' ) ) {
			return PartnerResolver::build_partner_context_from_code( $override_code );
		}

		// Otherwise use standard resolution
		return PartnerResolver::resolve_partner_context( $form_id );
	}

	/**
	 * Get default result structure
	 *
	 * @return array
	 */
	private static function get_default_result() : array {
		return [
			'product_id'              => 0,
			'base_price'              => 0.0,
			'booking_start_ts'        => 0,
			'days_before'             => 0,

			// EB discount
			'eb_eligible'             => false,
			'eb_config'               => [],
			'eb_source_category_id'   => 0,
			'eb_step'                 => [],
			'eb_discount_pct'         => 0.0,
			'eb_discount_amount'      => 0.0,

			// Partner discount
			'partner'                 => [ 'active' => false ],
			'partner_discount_amount' => 0.0,
			'partner_commission'      => 0.0,

			// Totals
			'total_discount'          => 0.0,
			'total_client'            => 0.0,
		];
	}

	/**
	 * Populate GF entry fields with ledger data
	 *
	 * Updates the _gravity_form_lead array with calculated ledger values
	 * for storage in cart item meta.
	 *
	 * @param array $lead   GF lead data (by reference)
	 * @param array $ledger Calculated ledger result
	 * @param int   $form_id GF form ID for field mapping
	 */
	public static function populate_lead_with_ledger( array &$lead, array $ledger, int $form_id ) : void {

		// Form 45 and 55 share the same field structure for ledger data
		if ( in_array( $form_id, [ 45, 55 ], true ) ) {
			$lead['15'] = (string) round( $ledger['base_price'], 2 );           // ledger_base_price
			$lead['16'] = (string) round( $ledger['eb_discount_pct'], 1 );      // ledger_eb_percent
			$lead['17'] = (string) round( $ledger['eb_discount_amount'], 2 );   // ledger_eb_discount
			$lead['18'] = (string) round( $ledger['partner_discount_amount'], 2 ); // ledger_partner_discount
			$lead['19'] = (string) round( $ledger['total_discount'], 2 );       // ledger_total_discount
			$lead['20'] = (string) round( $ledger['total_client'], 2 );         // ledger_total_client
			$lead['21'] = (string) round( $ledger['partner_commission'], 2 );   // ledger_partner_commission

			// Partner attribution fields
			$partner = $ledger['partner'] ?? [];
			if ( ! empty( $partner['active'] ) ) {
				$lead['25'] = (string) ( $partner['partner_user_id'] ?? '' );   // partner_user_id
				$lead['26'] = (string) ( $partner['code'] ?? '' );              // partner_coupon_code
				$lead['27'] = (string) ( $partner['discount_pct'] ?? '' );      // partner_discount_pct
				$lead['28'] = (string) ( $partner['commission_pct'] ?? '' );    // partner_commission_pct
				$lead['29'] = (string) ( $partner['partner_email'] ?? '' );     // partner_email
			}
		}
	}

	/**
	 * Clear calculation cache
	 */
	public static function clear_cache() : void {
		self::$calc_cache = [];
	}
}
