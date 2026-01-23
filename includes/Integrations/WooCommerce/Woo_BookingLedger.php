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
		$cart_item_data[ self::BK_LEDGER_BASE ]        = $ledger['base_price'];
		$cart_item_data[ self::BK_LEDGER_EB_PCT ]      = $ledger['eb_discount_pct'];
		$cart_item_data[ self::BK_LEDGER_EB_AMOUNT ]   = $ledger['eb_discount_amount'];
		$cart_item_data[ self::BK_LEDGER_PARTNER_AMT ] = $ledger['partner_discount_amount'];
		$cart_item_data[ self::BK_LEDGER_TOTAL ]       = $ledger['total_client'];
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

		// Show partner discount if applicable
		$partner_amount = $cart_item[ self::BK_LEDGER_PARTNER_AMT ] ?? 0;
		if ( $partner_amount > 0 ) {
			// Get partner code from lead using semantic field
			// Note: BookingLedger writes to KEY_COUPON_CODE, so read from there
			$lead    = $cart_item['_gravity_form_lead'] ?? [];
			$form_id = (int) ( $lead['form_id'] ?? 0 );
			$code    = GF_SemanticFields::entry_value( $lead, $form_id, GF_SemanticFields::KEY_COUPON_CODE );

			$item_data[] = [
				'key'     => __( 'Partner Discount', 'tc-booking-flow-next' ),
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
