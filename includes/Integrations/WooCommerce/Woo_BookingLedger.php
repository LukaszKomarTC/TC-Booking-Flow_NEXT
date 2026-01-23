<?php
namespace TC_BF\Integrations\WooCommerce;

use TC_BF\Admin\Settings;
use TC_BF\Domain\BookingLedger;
use TC_BF\Domain\PartnerResolver;
use TC_BF\Integrations\GravityForms\GF_SemanticFields;
use TC_BF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce Booking Ledger Integration
 *
 * Handles ledger calculations for WooCommerce Bookings products
 * with Gravity Forms Product Add-ons.
 *
 * This integration:
 * - Calculates EB discounts based on product category rules
 * - Resolves partner context and calculates partner discount
 * - Populates GF lead data with ledger values
 * - Applies the calculated price to cart items
 */
class Woo_BookingLedger {

	/**
	 * Booking meta keys
	 */
	const BK_LEDGER_BASE        = '_tcbf_ledger_base';
	const BK_LEDGER_EB_PCT      = '_tcbf_ledger_eb_pct';
	const BK_LEDGER_EB_AMOUNT   = '_tcbf_ledger_eb_amount';
	const BK_LEDGER_PARTNER_AMT = '_tcbf_ledger_partner_amount';
	const BK_LEDGER_TOTAL       = '_tcbf_ledger_total';
	const BK_LEDGER_COMMISSION  = '_tcbf_ledger_commission';
	const BK_LEDGER_PROCESSED   = '_tcbf_ledger_processed';

	/**
	 * Get the configured booking form ID
	 *
	 * Uses admin setting for flexibility (default 55).
	 *
	 * @return int Form ID
	 */
	private static function get_booking_form_id() : int {
		return Settings::get_booking_form_id();
	}

	/**
	 * Initialize hooks
	 */
	public static function init() : void {
		// Process cart items when added
		add_filter( 'woocommerce_add_cart_item_data', [ __CLASS__, 'process_cart_item_data' ], 25, 3 );

		// Apply ledger pricing during cart calculations
		add_action( 'woocommerce_before_calculate_totals', [ __CLASS__, 'apply_ledger_to_cart' ], 25, 1 );

		// Display ledger info in cart
		add_filter( 'woocommerce_get_item_data', [ __CLASS__, 'display_cart_item_ledger' ], 25, 2 );

		// Persist ledger to order
		add_action( 'woocommerce_checkout_create_order_line_item', [ __CLASS__, 'persist_ledger_to_order' ], 25, 4 );

		// AJAX endpoint for live ledger calculation (same logic as cart)
		add_action( 'wp_ajax_tcbf_calc_ledger', [ __CLASS__, 'ajax_calc_ledger' ] );
		add_action( 'wp_ajax_nopriv_tcbf_calc_ledger', [ __CLASS__, 'ajax_calc_ledger' ] );
	}

	/**
	 * AJAX handler for live ledger calculation
	 *
	 * Uses the same BookingLedger::calculate_for_booking() as cart processing,
	 * ensuring JS preview matches cart pricing exactly.
	 *
	 * Expected POST params:
	 * - product_id: WooCommerce product ID
	 * - base_price: Base price from WC Bookings (numeric, dot decimal)
	 * - start_date: Booking start date (Y-m-d format)
	 * - partner_code: Optional partner discount code
	 */
	public static function ajax_calc_ledger() : void {
		// Verify nonce if provided (optional for read-only endpoint)
		// Note: This is a read-only calculation, no sensitive data changes
		// We skip nonce for AJAX performance since calculation is idempotent

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$base_price = isset( $_POST['base_price'] ) ? (float) $_POST['base_price'] : 0.0;
		$start_date = isset( $_POST['start_date'] ) ? sanitize_text_field( $_POST['start_date'] ) : '';
		$partner_code = isset( $_POST['partner_code'] ) ? sanitize_text_field( $_POST['partner_code'] ) : '';

		// Validate required params
		if ( $product_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid product_id' ], 400 );
		}

		if ( $base_price <= 0 ) {
			wp_send_json_success( self::get_empty_ledger_response() );
			return;
		}

		// Parse start date to timestamp
		$start_ts = 0;
		if ( $start_date !== '' ) {
			// Parse Y-m-d format
			$parsed = \DateTime::createFromFormat( 'Y-m-d', $start_date );
			if ( $parsed ) {
				$parsed->setTime( 0, 0, 0 );
				$start_ts = $parsed->getTimestamp();
			} else {
				// Try strtotime as fallback
				$start_ts = strtotime( $start_date );
				if ( $start_ts === false ) {
					$start_ts = 0;
				}
			}
		}

		if ( $start_ts <= 0 ) {
			wp_send_json_success( self::get_empty_ledger_response() );
			return;
		}

		// Resolve partner context
		$partner_ctx = [];
		if ( $partner_code !== '' ) {
			$partner_ctx = PartnerResolver::build_partner_context_from_code( $partner_code );
		} else {
			// Try session/logged-in user partner resolution
			$partner_ctx = PartnerResolver::resolve_partner_context( 0 );
		}

		// Calculate ledger using the same function as cart processing
		$ledger = BookingLedger::calculate_for_booking(
			$product_id,
			$base_price,
			$start_ts,
			$partner_ctx
		);

		// Return simplified response for JS
		// IMPORTANT: total_after_eb is the cart price (EB-only), total_after_partner is preview only
		wp_send_json_success( [
			'base_price'         => round( $ledger['base_price'], 2 ),
			'days_before'        => $ledger['days_before'],
			'eb_pct'             => round( $ledger['eb_discount_pct'], 2 ),
			'eb_amount'          => round( $ledger['eb_discount_amount'], 2 ),
			'partner_pct'        => ! empty( $ledger['partner']['active'] ) ? (float) ( $ledger['partner']['discount_pct'] ?? 0 ) : 0,
			'partner_amount'     => round( $ledger['partner_discount_amount'], 2 ),
			'commission_pct'     => ! empty( $ledger['partner']['active'] ) ? (float) ( $ledger['partner']['commission_pct'] ?? 0 ) : 0,
			'commission'         => round( $ledger['partner_commission'], 2 ),
			'total_after_eb'     => round( $ledger['total_after_eb'], 2 ),     // Cart price (EB applied)
			'total_after_partner'=> round( $ledger['total_after_partner'], 2 ), // Preview only (partner via coupon)
			'total'              => round( $ledger['total_after_partner'], 2 ), // Backward compat alias
			'partner_active'     => ! empty( $ledger['partner']['active'] ),
			'partner_code'       => ! empty( $ledger['partner']['active'] ) ? ( $ledger['partner']['code'] ?? '' ) : '',
			'partner_email'      => ! empty( $ledger['partner']['active'] ) ? ( $ledger['partner']['partner_email'] ?? '' ) : '',
			'partner_user_id'    => ! empty( $ledger['partner']['active'] ) ? ( $ledger['partner']['partner_user_id'] ?? 0 ) : 0,
		] );
	}

	/**
	 * Get empty ledger response structure
	 *
	 * @return array Empty ledger values
	 */
	private static function get_empty_ledger_response() : array {
		return [
			'base_price'         => 0,
			'days_before'        => 0,
			'eb_pct'             => 0,
			'eb_amount'          => 0,
			'partner_pct'        => 0,
			'partner_amount'     => 0,
			'commission_pct'     => 0,
			'commission'         => 0,
			'total_after_eb'     => 0,
			'total_after_partner'=> 0,
			'total'              => 0,
			'partner_active'     => false,
			'partner_code'       => '',
			'partner_email'      => '',
			'partner_user_id'    => 0,
		];
	}

	/**
	 * Process cart item data when adding to cart
	 *
	 * Calculates ledger values and populates GF lead data.
	 *
	 * @param array $cart_item_data Cart item data
	 * @param int   $product_id     Product ID
	 * @param int   $variation_id   Variation ID
	 * @return array Modified cart item data
	 */
	public static function process_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ) : array {

		// Only process if we have booking data and GF data
		if ( empty( $cart_item_data['booking'] ) || empty( $cart_item_data['_gravity_form_lead'] ) ) {
			return $cart_item_data;
		}

		$booking = $cart_item_data['booking'];
		$lead    = $cart_item_data['_gravity_form_lead'];
		$form_id = (int) ( $lead['form_id'] ?? 0 );

		// Skip if not the configured booking form
		if ( $form_id !== self::get_booking_form_id() ) {
			return $cart_item_data;
		}

		// Skip if already processed
		if ( ! empty( $cart_item_data[ self::BK_LEDGER_PROCESSED ] ) ) {
			return $cart_item_data;
		}

		// Get booking details
		$base_price = (float) ( $booking['_cost'] ?? 0 );
		$start_ts   = (int) ( $booking['_start_date'] ?? 0 );

		if ( $base_price <= 0 ) {
			return $cart_item_data;
		}

		// Resolve partner context from the lead data
		$partner_ctx = self::resolve_partner_from_lead( $lead );

		// Calculate ledger
		$ledger = BookingLedger::calculate_for_booking(
			$product_id,
			$base_price,
			$start_ts,
			$partner_ctx
		);

		// Store ledger values in cart item
		// IMPORTANT: BK_LEDGER_TOTAL uses total_after_eb (EB-only price) for cart pricing
		// Partner discount is coupon-based, NOT baked into cart item price
		$cart_item_data[ self::BK_LEDGER_BASE ]        = $ledger['base_price'];
		$cart_item_data[ self::BK_LEDGER_EB_PCT ]      = $ledger['eb_discount_pct'];
		$cart_item_data[ self::BK_LEDGER_EB_AMOUNT ]   = $ledger['eb_discount_amount'];
		$cart_item_data[ self::BK_LEDGER_PARTNER_AMT ] = $ledger['partner_discount_amount']; // For reporting only
		$cart_item_data[ self::BK_LEDGER_TOTAL ]       = $ledger['total_after_eb']; // EB-only price for cart
		$cart_item_data[ self::BK_LEDGER_COMMISSION ]  = $ledger['partner_commission'];
		$cart_item_data[ self::BK_LEDGER_PROCESSED ]   = true;

		// Populate GF lead with ledger data
		BookingLedger::populate_lead_with_ledger( $lead, $ledger, $form_id );
		$cart_item_data['_gravity_form_lead'] = $lead;

		// Log the calculation
		Logger::log( 'woo.booking_ledger.calculated', [
			'product_id'      => $product_id,
			'base_price'      => $base_price,
			'start_ts'        => $start_ts,
			'eb_pct'          => $ledger['eb_discount_pct'],
			'eb_amount'       => $ledger['eb_discount_amount'],
			'partner_amount'  => $ledger['partner_discount_amount'],
			'total_client'    => $ledger['total_client'],
			'partner_active'  => ! empty( $ledger['partner']['active'] ),
		] );

		return $cart_item_data;
	}

	/**
	 * Resolve partner context from GF lead data
	 *
	 * @param array $lead GF lead data
	 * @return array Partner context
	 */
	private static function resolve_partner_from_lead( array $lead ) : array {

		$form_id = (int) ( $lead['form_id'] ?? 0 );

		// Check for admin override (only read if field exists)
		$admin_field = GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_OVERRIDE_CODE );
		if ( $admin_field > 0 ) {
			$override_code = trim( (string) ( $lead[ (string) $admin_field ] ?? '' ) );
			if ( $override_code !== '' && current_user_can( 'administrator' ) ) {
				return PartnerResolver::build_partner_context_from_code( $override_code );
			}
		}

		// Check for coupon code (only read if field exists)
		$coupon_field = GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_COUPON_CODE );
		if ( $coupon_field > 0 ) {
			$coupon_code = trim( (string) ( $lead[ (string) $coupon_field ] ?? '' ) );
			if ( $coupon_code !== '' ) {
				return PartnerResolver::build_partner_context_from_code( $coupon_code );
			}
		}

		// Fall back to standard resolution
		return PartnerResolver::resolve_partner_context( $form_id );
	}

	/**
	 * Apply ledger pricing to cart items
	 *
	 * @param \WC_Cart $cart WooCommerce cart
	 */
	public static function apply_ledger_to_cart( $cart ) : void {

		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $key => $item ) {

			// Only process items with our ledger data
			if ( empty( $item[ self::BK_LEDGER_PROCESSED ] ) ) {
				continue;
			}

			// Get the calculated total price
			$total_price = $item[ self::BK_LEDGER_TOTAL ] ?? null;
			if ( $total_price === null || $total_price < 0 ) {
				continue;
			}

			// Apply the ledger price to the product
			$product = $item['data'] ?? null;
			if ( $product && is_object( $product ) && method_exists( $product, 'set_price' ) ) {
				$product->set_price( (float) $total_price );

				Logger::log( 'woo.booking_ledger.apply_price', [
					'cart_key'    => $key,
					'total_price' => $total_price,
				] );
			}
		}
	}

	/**
	 * Display ledger information in cart
	 *
	 * @param array $item_data Existing item data
	 * @param array $cart_item Cart item
	 * @return array Modified item data
	 */
	public static function display_cart_item_ledger( array $item_data, array $cart_item ) : array {

		if ( empty( $cart_item[ self::BK_LEDGER_PROCESSED ] ) ) {
			return $item_data;
		}

		$eb_amount = $cart_item[ self::BK_LEDGER_EB_AMOUNT ] ?? 0;
		$eb_pct    = $cart_item[ self::BK_LEDGER_EB_PCT ] ?? 0;

		// Show EB discount if applicable
		if ( $eb_amount > 0 ) {
			$item_data[] = [
				'key'     => __( 'Early Booking', 'tc-booking-flow-next' ),
				'value'   => sprintf(
					'-%s (%s%%)',
					wc_price( $eb_amount ),
					number_format( $eb_pct, 0 )
				),
				'display' => '',
			];
		}

		// Show partner discount preview if applicable
		// Note: Partner discount is applied via WC coupon, NOT baked into item price
		// This display is for informational purposes only
		$partner_amount = $cart_item[ self::BK_LEDGER_PARTNER_AMT ] ?? 0;
		if ( $partner_amount > 0 ) {
			// Get partner code from lead using semantic field
			// Note: BookingLedger writes to KEY_COUPON_CODE, so read from there
			$lead    = $cart_item['_gravity_form_lead'] ?? [];
			$form_id = (int) ( $lead['form_id'] ?? 0 );
			$code    = GF_SemanticFields::entry_value( $lead, $form_id, GF_SemanticFields::KEY_COUPON_CODE );

			$item_data[] = [
				'key'     => __( 'Partner Discount (via coupon)', 'tc-booking-flow-next' ),
				'value'   => sprintf(
					'-%s%s',
					wc_price( $partner_amount ),
					$code ? " ({$code})" : ''
				),
				'display' => '',
			];
		}

		return $item_data;
	}

	/**
	 * Persist ledger data to order line item
	 *
	 * @param \WC_Order_Item_Product $item         Order item
	 * @param string                 $cart_item_key Cart item key
	 * @param array                  $values       Cart item values
	 * @param \WC_Order              $order        Order object
	 */
	public static function persist_ledger_to_order( $item, $cart_item_key, $values, $order ) : void {

		if ( empty( $values[ self::BK_LEDGER_PROCESSED ] ) ) {
			return;
		}

		// Store ledger meta on order item
		$item->add_meta_data( '_tcbf_ledger_base', $values[ self::BK_LEDGER_BASE ] ?? 0 );
		$item->add_meta_data( '_tcbf_ledger_eb_pct', $values[ self::BK_LEDGER_EB_PCT ] ?? 0 );
		$item->add_meta_data( '_tcbf_ledger_eb_amount', $values[ self::BK_LEDGER_EB_AMOUNT ] ?? 0 );
		$item->add_meta_data( '_tcbf_ledger_partner_amount', $values[ self::BK_LEDGER_PARTNER_AMT ] ?? 0 );
		$item->add_meta_data( '_tcbf_ledger_total', $values[ self::BK_LEDGER_TOTAL ] ?? 0 );
		$item->add_meta_data( '_tcbf_ledger_commission', $values[ self::BK_LEDGER_COMMISSION ] ?? 0 );

		// Store partner attribution using semantic fields
		$lead    = $values['_gravity_form_lead'] ?? [];
		$form_id = (int) ( $lead['form_id'] ?? 0 );

		$partner_user_id = GF_SemanticFields::entry_value( $lead, $form_id, GF_SemanticFields::KEY_PARTNER_USER_ID );
		if ( ! empty( $partner_user_id ) ) {
			$item->add_meta_data( '_tcbf_partner_user_id', $partner_user_id );
		}

		// Note: BookingLedger writes partner code to KEY_COUPON_CODE, so read from there
		$partner_code = GF_SemanticFields::entry_value( $lead, $form_id, GF_SemanticFields::KEY_COUPON_CODE );
		if ( ! empty( $partner_code ) ) {
			$item->add_meta_data( '_tcbf_partner_code', $partner_code );
		}

		$partner_email = GF_SemanticFields::entry_value( $lead, $form_id, GF_SemanticFields::KEY_PARTNER_EMAIL );
		if ( ! empty( $partner_email ) ) {
			$item->add_meta_data( '_tcbf_partner_email', $partner_email );
		}

		Logger::log( 'woo.booking_ledger.persist_to_order', [
			'order_id' => $order->get_id(),
			'item_id'  => $item->get_id(),
			'ledger'   => [
				'base'           => $values[ self::BK_LEDGER_BASE ] ?? 0,
				'eb_amount'      => $values[ self::BK_LEDGER_EB_AMOUNT ] ?? 0,
				'partner_amount' => $values[ self::BK_LEDGER_PARTNER_AMT ] ?? 0,
				'total'          => $values[ self::BK_LEDGER_TOTAL ] ?? 0,
			],
		] );
	}

	/**
	 * Calculate ledger for an existing cart item (for recalculation)
	 *
	 * @param array $cart_item Cart item
	 * @return array|null Ledger data or null if not applicable
	 */
	public static function calculate_for_cart_item( array $cart_item ) : ?array {

		if ( empty( $cart_item['booking'] ) || empty( $cart_item['_gravity_form_lead'] ) ) {
			return null;
		}

		$lead    = $cart_item['_gravity_form_lead'];
		$form_id = (int) ( $lead['form_id'] ?? 0 );

		if ( $form_id !== self::get_booking_form_id() ) {
			return null;
		}

		return BookingLedger::calculate_for_cart_item( $cart_item );
	}
}
