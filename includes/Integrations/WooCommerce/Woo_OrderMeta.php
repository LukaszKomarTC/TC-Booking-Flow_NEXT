<?php
namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce Order Metadata Handler
 *
 * Handles:
 * - Creating order line items from cart
 * - Persisting partner information to order meta
 * - Writing early booking ledger to order meta
 */
class Woo_OrderMeta {

	// Internal meta key prefixes/keys that should never be displayed to customers
	const INTERNAL_META_PREFIXES = [ 'TC_', 'TCBF_', 'tcbf_', '_tcbf_', '_tc_', 'tc_', '_eb_', '_gf_' ];

	// Explicit list of ALL internal meta keys to hide (both with and without underscore prefix)
	const HIDDEN_META_KEYS = [
		// TC group/pack meta
		'tc_group_id', 'TC_GROUP_ID', '_tc_group_id',
		'tc_group_role', 'TC_GROUP_ROLE', '_tc_group_role',

		// TCBF scope/booking meta
		'tcbf_scope', 'TCBF_SCOPE', '_tcbf_scope',
		'tcbf_event_id', 'TCBF_EVENT_ID', '_tcbf_event_id',

		// Event/participant meta (stored without prefix)
		'event', 'EVENT', '_event',
		'event_id', '_event_id',
		'event_title', '_event_title',
		'participant', 'PARTICIPANT', '_participant',
		'participant_email', '_participant_email',
		'bicycle', 'BICYCLE', '_bicycle',
		'client', 'CLIENT', '_client',

		// Booking/cost meta
		'confirmation', '_confirmation',
		'email', '_email',
		'custom_cost', '_custom_cost',
		'entry_id', '_entry_id',

		// EB discount meta
		'eb_pct', '_eb_pct',
		'eb_amount', '_eb_amount',
		'eb_eligible', '_eb_eligible',
		'eb_days_before', '_eb_days_before',
		'eb_base', '_eb_base',
		'eb_event_ts', '_eb_event_ts',

		// TC scope
		'tc_scope', '_tc_scope',

		// GF meta
		'gf_entry_id', '_gf_entry_id',
	];

	// Booking meta keys stored on cart items
	const BK_EVENT_ID      = '_event_id';
	const BK_EVENT_TITLE   = '_event_title';
	const BK_ENTRY_ID      = '_entry_id';
	const BK_CUSTOM_COST   = '_custom_cost';

	const BK_SCOPE         = '_tc_scope';          // 'participation' | 'rental'
	const BK_EB_PCT        = '_eb_pct';            // snapshot pct
	const BK_EB_AMOUNT     = '_eb_amount';         // snapshot discount amount per line (per unit)
	const BK_EB_ELIGIBLE   = '_eb_eligible';       // 0/1
	const BK_EB_DAYS       = '_eb_days_before';    // snapshot days
	const BK_EB_BASE       = '_eb_base_price';     // snapshot base (per line, before EB)
	const BK_EB_EVENT_TS   = '_eb_event_start_ts'; // snapshot for audit

	/* =========================================================
	 * Cart → Order meta copy-through (extend your existing behavior)
	 * ========================================================= */

	public static function woo_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {

		$cart_item = WC()->cart ? WC()->cart->get_cart_item( $cart_item_key ) : [];
		$booking   = ( isset($cart_item['booking']) && is_array($cart_item['booking']) ) ? $cart_item['booking'] : [];

		if ( ! empty( $booking[self::BK_EVENT_ID] ) ) {
			$item->add_meta_data( '_event_id', $booking[self::BK_EVENT_ID] );
		}
		if ( ! empty( $booking[self::BK_EVENT_TITLE] ) ) {
			$item->add_meta_data( 'event', $booking[self::BK_EVENT_TITLE] );
		}
		if ( ! empty( $booking['_participant'] ) ) {
			$item->add_meta_data( 'participant', $booking['_participant'] );
		}
		if ( ! empty( $booking['_bicycle'] ) ) {
			$item->add_meta_data( '_bicycle', $booking['_bicycle'] );
		}
		if ( ! empty( $booking[self::BK_ENTRY_ID] ) ) {
			$item->add_meta_data( '_gf_entry_id', $booking[self::BK_ENTRY_ID] );
		}
		if ( ! empty( $booking['_participant_email'] ) ) {
			$item->add_meta_data( 'email', $booking['_participant_email'] );
		}
		if ( ! empty( $booking['_confirmation'] ) ) {
			$item->add_meta_data( 'confirmation', __('[:es]Enviar email de confirmación al participante[:en]Send email confirmation to participant[:]') );
		}

		// New: scope + EB snapshot audit
		if ( ! empty( $booking[self::BK_SCOPE] ) ) {
			$item->add_meta_data( '_tc_scope', $booking[self::BK_SCOPE] );
		}
		if ( isset($booking[self::BK_EB_PCT]) ) {
			$item->add_meta_data( '_eb_pct', wc_format_decimal((float)$booking[self::BK_EB_PCT], 2) );
		}
		if ( isset($booking[self::BK_EB_AMOUNT]) ) {
			$item->add_meta_data( '_eb_amount', wc_format_decimal((float)$booking[self::BK_EB_AMOUNT], 2) );
		}
		if ( isset($booking[self::BK_EB_ELIGIBLE]) ) {
			$item->add_meta_data( '_eb_eligible', (int) $booking[self::BK_EB_ELIGIBLE] );
		}
		if ( isset($booking[self::BK_EB_DAYS]) ) {
			$item->add_meta_data( '_eb_days_before', (int) $booking[self::BK_EB_DAYS] );
		}
		if ( isset($booking[self::BK_EB_BASE]) ) {
			$item->add_meta_data( '_eb_base_price', wc_format_decimal((float)$booking[self::BK_EB_BASE], 2) );
		}
		if ( isset($booking[self::BK_EB_EVENT_TS]) ) {
			$item->add_meta_data( '_eb_event_start_ts', (string) $booking[self::BK_EB_EVENT_TS] );
		}
	}

	/* =========================================================
	 * Partner meta on order (kept, improved base detection)
	 * ========================================================= */

	public static function partner_persist_order_meta( $order, $data ) {

		if ( ! $order || ! is_a($order, 'WC_Order') ) return;

		if ( $order->get_meta('partner_code') || $order->get_meta('partner_commission') || $order->get_meta('client_total') ) {
			return;
		}

		$coupon_codes = $order->get_coupon_codes();

		// Partner gate warning: check if logged-in user is a partner but their coupon is missing
		self::maybe_add_partner_gate_note( $order, $coupon_codes );

		if ( empty($coupon_codes) ) return;

		$partner_user_id = 0;
		$partner_code    = '';

		foreach ( $coupon_codes as $code ) {
			$code = wc_format_coupon_code( $code );
			if ( $code === '' ) continue;

			$users = get_users([
				'meta_key'   => 'discount__code',
				'meta_value' => $code,
				'number'     => 1,
				'fields'     => 'ids',
			]);

			if ( ! empty($users[0]) ) {
				$partner_user_id = (int) $users[0];
				$partner_code    = $code;
				break;
			}
		}

		if ( ! $partner_user_id || $partner_code === '' ) return;


		// TCBF-12: strict mode — if ANY event in order has partners disabled, do not persist partner meta.
		foreach ( $order->get_items() as $item ) {
			$event_id = (int) $item->get_meta('_event_id', true);
			if ( $event_id > 0 && ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
				return;
			}
		}


		$partner_commission_rate = (float) get_user_meta( $partner_user_id, 'usrdiscount', true );
		if ( $partner_commission_rate < 0 ) $partner_commission_rate = 0;

		$partner_discount_pct = 0.0;
		$partner_coupon_type  = '';
		try {
			$coupon = new \WC_Coupon( $partner_code );
			$partner_coupon_type = (string) $coupon->get_discount_type();
			if ( $partner_coupon_type === 'percent' ) {
				$partner_discount_pct = (float) $coupon->get_amount();
				if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0;
			}
		} catch ( \Exception $e ) {}

		// Base before EB and before coupons: prefer _eb_base_price snapshots if present
		$subtotal_original = 0.0;
		foreach ( $order->get_items() as $item ) {

			$event_id = $item->get_meta('_event_id', true);
			if ( ! $event_id ) continue;

			$base = $item->get_meta('_eb_base_price', true);
			if ( $base !== '' ) {
				$subtotal_original += (float) $base * max(1, (int) $item->get_quantity());
			} else {
				$subtotal_original += (float) $item->get_subtotal();
			}
		}
		if ( $subtotal_original <= 0 ) $subtotal_original = (float) $order->get_subtotal();
		$subtotal_original = self::money_round( (float) $subtotal_original );

		// EB pct stored on order later by ledger; here we set placeholder 0 (ledger updates after)
		$early_booking_discount_pct = 0.0;
		$partner_base_total = $subtotal_original;

		$partner_base_total = self::money_round( (float) $partner_base_total );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = self::money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$client_total       = self::money_round( max(0.0, $partner_base_total - $client_discount) );
		$partner_commission = self::money_round( $partner_base_total * ($partner_commission_rate / 100) );

		$order->update_meta_data('partner_id', (string) $partner_user_id);
		$order->update_meta_data('partner_code', $partner_code);

		$order->update_meta_data('partner_coupon_type', $partner_coupon_type);
		$order->update_meta_data('partner_discount_pct', wc_format_decimal($partner_discount_pct, 2));
		$order->update_meta_data('partner_commission_rate', wc_format_decimal($partner_commission_rate, 2));

		$order->update_meta_data('early_booking_discount_pct', wc_format_decimal($early_booking_discount_pct, 2));
		$order->update_meta_data('subtotal_original', wc_format_decimal($subtotal_original, 2));
		$order->update_meta_data('partner_base_total', wc_format_decimal($partner_base_total, 2));

		$order->update_meta_data('client_total', wc_format_decimal($client_total, 2));
		$order->update_meta_data('client_discount', wc_format_decimal($client_discount, 2));
		$order->update_meta_data('partner_commission', wc_format_decimal($partner_commission, 2));
		$order->update_meta_data('tc_ledger_version', '2');

		$order->save();
	}

	/* =========================================================
	 * EB + partner ledger (snapshot-driven)
	 * ========================================================= */

	public static function eb_write_order_ledger( $order_id, $posted_data, $order ) {

		if ( ! $order || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order($order_id);
			if ( ! $order ) return;
		}

		$subtotal_original = 0.0;
		$eb_amount_total   = 0.0;
		$eb_pct_seen       = null;
		$eb_days_seen      = null;
		$start_event_id    = 0;
		$start_ts          = 0;

		foreach ( $order->get_items() as $item ) {

			$event_id = (int) $item->get_meta('_event_id', true);
			if ( $event_id <= 0 ) continue;

			$start_event_id = $start_event_id ?: $event_id;

			// base snapshot
			$base = $item->get_meta('_eb_base_price', true);
			$qty  = max(1, (int) $item->get_quantity());

			$line_base = ($base !== '') ? ((float)$base * $qty) : (float) $item->get_subtotal();

			$subtotal_original += $line_base;

			$eligible = (int) $item->get_meta('_eb_eligible', true);
			$pct = (float) $item->get_meta('_eb_pct', true);
			$amt = (float) $item->get_meta('_eb_amount', true);

			if ( $eligible && $base !== '' ) {
				if ( $amt > 0 ) {
					$eb_amount_total += min($line_base, $amt * $qty);
				} elseif ( $pct > 0 ) {
					$eb_amount_total += $line_base - ($line_base * (1 - ($pct/100)));
				}
				if ( $eb_pct_seen === null ) $eb_pct_seen = $pct;
				$days = $item->get_meta('_eb_days_before', true);
				if ( $days !== '' && $eb_days_seen === null ) $eb_days_seen = (int) $days;
				$ts = $item->get_meta('_eb_event_start_ts', true);
				if ( $ts !== '' && ! $start_ts ) $start_ts = (int) $ts;
			}
		}

		if ( $subtotal_original <= 0 ) return;

		// Normalize monetary aggregates to currency cents to prevent 0.01 drift.
		$subtotal_original = self::money_round( (float) $subtotal_original );
		$eb_amount_total   = self::money_round( (float) $eb_amount_total );

		$partner_discount_pct    = (float) $order->get_meta('partner_discount_pct', true);
		$partner_commission_rate = (float) $order->get_meta('partner_commission_rate', true);
		if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0;
		if ( $partner_commission_rate < 0 ) $partner_commission_rate = 0;

		$partner_base_total = self::money_round( max(0, $subtotal_original - $eb_amount_total) );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = self::money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$client_total       = self::money_round( max(0.0, $partner_base_total - $client_discount) );
		$partner_commission = self::money_round( $partner_base_total * ($partner_commission_rate / 100) );

		$order->update_meta_data('subtotal_original', wc_format_decimal($subtotal_original, 2));

		if ( $start_event_id ) $order->update_meta_data('eb_event_id', (string)$start_event_id);
		if ( $start_ts ) $order->update_meta_data('eb_event_start_ts', (string)$start_ts);

		$order->update_meta_data('early_booking_discount_pct', wc_format_decimal((float)($eb_pct_seen ?? 0.0), 2));
		$order->update_meta_data('early_booking_discount_amount', wc_format_decimal($eb_amount_total, 2));

		if ( $eb_days_seen !== null ) {
			$order->update_meta_data('eb_days_before', (string)$eb_days_seen);
		}

		$order->update_meta_data('partner_base_total', wc_format_decimal($partner_base_total, 2));
		$order->update_meta_data('client_total', wc_format_decimal($client_total, 2));
		$order->update_meta_data('client_discount', wc_format_decimal($client_discount, 2));
		$order->update_meta_data('partner_commission', wc_format_decimal($partner_commission, 2));
		$order->update_meta_data('tc_ledger_version', '2');

		$order->save();
	}

	/**
	 * Round money values to currency cents consistently.
	 * We round at each ledger output step to avoid 0.01 drift between GF and PHP.
	 */
	private static function money_round( float $v ) : float {
		// tiny epsilon mitigates binary float artifacts like 19.999999 -> 20.00
		return round($v + 1e-9, 2);
	}

	/**
	 * Add order note ONLY when there's explicit partner intent but coupon is missing.
	 *
	 * Intent-based: only fires when admin explicitly selected a partner in the booking flow
	 * (via _tcbf_partner_intent_id meta or session), NOT for every partner user placing orders.
	 *
	 * This prevents noise from:
	 * - Partner users intentionally removing their coupon (opt-out by policy)
	 * - Partner users placing unrelated orders
	 * - Test checkouts without coupon
	 *
	 * @param \WC_Order $order        The order being processed
	 * @param array     $coupon_codes Coupon codes applied to the order
	 */
	private static function maybe_add_partner_gate_note( \WC_Order $order, array $coupon_codes ) : void {

		// Check for explicit partner intent marker
		// This is set by admin booking flow when partner is explicitly selected
		$intent_partner_id = 0;

		// Check order meta for intent (set during admin order creation)
		$meta_intent = $order->get_meta( '_tcbf_partner_intent_id', true );
		if ( $meta_intent !== '' && (int) $meta_intent > 0 ) {
			$intent_partner_id = (int) $meta_intent;
		}

		// Also check WC session for frontend flows with explicit partner context
		if ( $intent_partner_id <= 0 && function_exists( 'WC' ) && WC() && WC()->session ) {
			$session_intent = WC()->session->get( 'tcbf_partner_intent_id' );
			if ( $session_intent !== null && (int) $session_intent > 0 ) {
				$intent_partner_id = (int) $session_intent;
				// Clear session after use
				WC()->session->set( 'tcbf_partner_intent_id', null );
			}
		}

		// No explicit intent — do not add note (this is normal behavior)
		if ( $intent_partner_id <= 0 ) {
			return;
		}

		// Intent exists — check if the intended partner's coupon is present
		$intent_partner_code = (string) get_user_meta( $intent_partner_id, 'discount__code', true );
		if ( $intent_partner_code === '' ) {
			return; // Partner has no code configured, nothing to warn about
		}

		// Normalize the partner code for comparison
		$intent_code_norm = function_exists( 'wc_format_coupon_code' )
			? wc_format_coupon_code( $intent_partner_code )
			: strtolower( trim( $intent_partner_code ) );

		if ( $intent_code_norm === '' ) return;

		// Normalize applied coupon codes
		$applied_codes_norm = array_map( function( $code ) {
			return function_exists( 'wc_format_coupon_code' )
				? wc_format_coupon_code( (string) $code )
				: strtolower( trim( (string) $code ) );
		}, $coupon_codes );

		// Check if intended partner's coupon is among the applied coupons
		if ( in_array( $intent_code_norm, $applied_codes_norm, true ) ) {
			return; // Coupon is present, gate will pass normally
		}

		// Intent existed but coupon missing — add admin warning note
		$partner_user = get_userdata( $intent_partner_id );
		$partner_name = $partner_user ? $partner_user->display_name : "ID #{$intent_partner_id}";

		$order->add_order_note(
			sprintf(
				/* translators: 1: partner name, 2: partner coupon code */
				__( 'Partner gate: partner "%1$s" was selected but coupon "%2$s" was not present at checkout; partner attribution skipped.', 'tc-booking-flow' ),
				$partner_name,
				$intent_code_norm
			),
			0, // Not customer note
			true // Added by system
		);
	}

	/* =========================================================
	 * Filters: Hide internal meta from order item display
	 * ========================================================= */

	/**
	 * Add internal meta keys to WooCommerce's hidden order item meta list.
	 *
	 * This is the CANONICAL way to hide meta keys in WooCommerce.
	 * These keys will never be displayed in order views or emails.
	 *
	 * @param array $hidden_meta Array of meta keys to hide
	 * @return array Extended array with our internal keys
	 */
	public static function filter_hidden_order_itemmeta( $hidden_meta ) {
		// Merge our hidden keys with WooCommerce defaults
		return array_unique( array_merge( $hidden_meta, self::HIDDEN_META_KEYS ) );
	}

	/**
	 * Remove internal meta keys from order item formatted meta display (backup filter).
	 *
	 * This filter runs whenever Woo prepares item meta for display (My Account order table, emails).
	 * It acts as a backup to catch anything that slips through woocommerce_hidden_order_itemmeta.
	 *
	 * Uses aggressive pattern matching: prefix check + explicit list + case-insensitive.
	 *
	 * @param array $formatted_meta Array of formatted meta objects
	 * @param \WC_Order_Item $item The order item
	 * @return array Filtered meta array
	 */
	public static function filter_order_item_meta( $formatted_meta, $item ) {
		// Only affect frontend + emails (skip wp-admin order edit for debugging)
		if ( is_admin() && ! wp_doing_ajax() && ! defined( 'DOING_CRON' ) ) {
			// Allow in admin if it's email preview or AJAX
			if ( ! doing_action( 'woocommerce_email_order_details' ) ) {
				return $formatted_meta;
			}
		}

		foreach ( $formatted_meta as $id => $meta ) {
			$key = isset( $meta->key ) ? (string) $meta->key : '';
			if ( $key === '' ) continue;

			// Normalize for comparison
			$key_lower    = strtolower( $key );
			$key_stripped = ltrim( $key_lower, '_' );

			// Check prefixes (case-insensitive)
			foreach ( self::INTERNAL_META_PREFIXES as $prefix ) {
				$prefix_lower = strtolower( $prefix );
				if ( strpos( $key_lower, $prefix_lower ) === 0 || strpos( $key_stripped, $prefix_lower ) === 0 ) {
					unset( $formatted_meta[ $id ] );
					continue 2;
				}
			}

			// Check explicit key list (case-insensitive)
			foreach ( self::HIDDEN_META_KEYS as $hidden_key ) {
				if ( strtolower( $hidden_key ) === $key_lower || strtolower( $hidden_key ) === $key_stripped ) {
					unset( $formatted_meta[ $id ] );
					continue 2;
				}
			}

			// Extra pattern: anything that looks like internal booking meta
			// Patterns: contains "group_id", "group_role", "scope", starts with numbers as IDs, etc.
			if ( preg_match( '/^(tc|tcbf|gf|eb)_/i', $key_stripped ) ||
			     preg_match( '/_(id|scope|role|eligible|amount|pct)$/i', $key_lower ) ) {
				unset( $formatted_meta[ $id ] );
			}
		}

		return $formatted_meta;
	}

	/**
	 * Filter order totals to hide generic Discount row on order page.
	 *
	 * We show partner discount in our enhanced blocks instead, avoiding duplication.
	 * Only applies to order view (My Account / Thank You), not cart/checkout/emails.
	 *
	 * @param array $total_rows Array of total rows
	 * @param \WC_Order $order The order
	 * @param string $tax_display Tax display mode
	 * @return array Filtered total rows
	 */
	public static function filter_order_totals_hide_discount( $total_rows, $order, $tax_display ) {
		// Only filter on frontend order view (not admin, not emails)
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $total_rows;
		}

		// Check if we're in an email context - don't filter emails
		if ( doing_action( 'woocommerce_email_order_details' ) ||
		     doing_action( 'woocommerce_email_before_order_table' ) ||
		     doing_action( 'woocommerce_email_after_order_table' ) ) {
			return $total_rows;
		}

		// Only filter if this is a booking order (has our enhanced blocks)
		if ( ! self::is_booking_order( $order ) ) {
			return $total_rows;
		}

		// Remove the discount row - we show it in our enhanced blocks
		if ( isset( $total_rows['discount'] ) ) {
			unset( $total_rows['discount'] );
		}

		return $total_rows;
	}

	/* =========================================================
	 * Enhanced discount/commission blocks for order view
	 * ========================================================= */

	/**
	 * Render enhanced discount and commission blocks after order table.
	 *
	 * Shows:
	 * - Early Booking discount (if applicable)
	 * - Partner discount (if applicable)
	 * - Partner commission (admin or partner-owner only)
	 *
	 * @param \WC_Order $order The order object
	 */
	public static function render_enhanced_blocks( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Output styles (once per page)
		self::output_enhanced_styles();

		$currency = $order->get_currency();

		// === Early Booking Discount Block ===
		$eb_amount = (float) $order->get_meta( 'early_booking_discount_amount', true );
		$eb_pct    = (float) $order->get_meta( 'early_booking_discount_pct', true );

		if ( $eb_amount > 0 ) {
			$eb_sub = $eb_pct > 0
				? sprintf( __( '%s%% applied to your booking', TC_BF_TEXTDOMAIN ), number_format_i18n( $eb_pct, 0 ) )
				: __( 'Applied to your booking', TC_BF_TEXTDOMAIN );

			echo '<div class="tcbf-enhanced-wrap tcbf-eb-enhanced">';
			echo '<div>';
			echo '<div class="tcbf-enhanced-title">' . esc_html__( 'Early booking discount', TC_BF_TEXTDOMAIN ) . '</div>';
			echo '<div class="tcbf-enhanced-sub">' . esc_html( $eb_sub ) . '</div>';
			echo '</div>';
			echo '<div class="tcbf-enhanced-amount">-' . wp_kses_post( wc_price( $eb_amount, [ 'currency' => $currency ] ) ) . '</div>';
			echo '</div>';
		}

		// === Partner Discount Block ===
		$partner_id = (int) $order->get_meta( 'partner_id', true );
		if ( $partner_id > 0 ) {
			// Use Woo authoritative discount (same as portal)
			$discount_total = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
			$partner_pct    = (float) $order->get_meta( 'partner_discount_pct', true );

			if ( $discount_total > 0 ) {
				$partner_sub = $partner_pct > 0
					? sprintf( __( '%s%% partner discount', TC_BF_TEXTDOMAIN ), number_format_i18n( $partner_pct, 0 ) )
					: __( 'Partner discount applied', TC_BF_TEXTDOMAIN );

				echo '<div class="tcbf-enhanced-wrap tcbf-partner-enhanced">';
				echo '<div>';
				echo '<div class="tcbf-enhanced-title">' . esc_html__( 'Partner discount', TC_BF_TEXTDOMAIN ) . '</div>';
				echo '<div class="tcbf-enhanced-sub">' . esc_html( $partner_sub ) . '</div>';
				echo '</div>';
				echo '<div class="tcbf-enhanced-amount">-' . wp_kses_post( wc_price( $discount_total, [ 'currency' => $currency ] ) ) . '</div>';
				echo '</div>';
			}

			// === Commission Block (Admin / Partner-owner only) ===
			$viewer_id = get_current_user_id();
			$is_admin  = current_user_can( 'manage_woocommerce' );
			$is_partner_owner = ( $partner_id > 0 && $viewer_id === $partner_id );

			if ( $is_admin || $is_partner_owner ) {
				$commission = (float) $order->get_meta( 'partner_commission', true );

				if ( $commission > 0 ) {
					$commission_rate = (float) $order->get_meta( 'partner_commission_rate', true );
					$comm_sub = $commission_rate > 0
						? sprintf( __( '%s%% of base total', TC_BF_TEXTDOMAIN ), number_format_i18n( $commission_rate, 0 ) )
						: __( 'Based on order total', TC_BF_TEXTDOMAIN );

					echo '<div class="tcbf-enhanced-wrap tcbf-commission-enhanced">';
					echo '<div>';
					echo '<div class="tcbf-enhanced-title">' . esc_html__( 'Partner commission', TC_BF_TEXTDOMAIN ) . '</div>';
					echo '<div class="tcbf-enhanced-sub">' . esc_html( $comm_sub ) . '</div>';
					echo '</div>';
					echo '<div class="tcbf-enhanced-amount">' . wp_kses_post( wc_price( $commission, [ 'currency' => $currency ] ) ) . '</div>';
					echo '</div>';
				}
			}
		}
	}

	/**
	 * Output CSS styles for enhanced blocks (only once per page).
	 */
	private static function output_enhanced_styles() {
		static $output = false;
		if ( $output ) return;
		$output = true;

		?>
		<style>
		/* Enhanced discount/commission blocks */
		.tcbf-enhanced-wrap {
			margin: 14px 0;
			border-radius: 10px;
			padding: 16px 20px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
		}
		.tcbf-enhanced-title {
			font-weight: 700;
			font-size: 15px;
		}
		.tcbf-enhanced-sub {
			opacity: 0.9;
			font-size: 13px;
			margin-top: 2px;
		}
		.tcbf-enhanced-amount {
			font-weight: 800;
			font-size: 18px;
			white-space: nowrap;
		}
		/* Early Booking - dark gradient, white text */
		.tcbf-eb-enhanced {
			background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);
		}
		.tcbf-eb-enhanced .tcbf-enhanced-title,
		.tcbf-eb-enhanced .tcbf-enhanced-sub,
		.tcbf-eb-enhanced .tcbf-enhanced-amount {
			color: #fff;
		}
		/* Partner discount - light green gradient */
		.tcbf-partner-enhanced {
			background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
			border: 1px solid #bbf7d0;
		}
		.tcbf-partner-enhanced .tcbf-enhanced-title,
		.tcbf-partner-enhanced .tcbf-enhanced-sub,
		.tcbf-partner-enhanced .tcbf-enhanced-amount {
			color: #111827;
		}
		/* Commission - light indigo gradient */
		.tcbf-commission-enhanced {
			background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
			border: 1px solid #c7d2fe;
		}
		.tcbf-commission-enhanced .tcbf-enhanced-title,
		.tcbf-commission-enhanced .tcbf-enhanced-sub,
		.tcbf-commission-enhanced .tcbf-enhanced-amount {
			color: #111827;
		}
		</style>
		<?php
	}

	/* =========================================================
	 * TCBF Summary Block (replaces Bookings "white patch")
	 * ========================================================= */

	/**
	 * Render TCBF Summary block before order table.
	 *
	 * Shows clean booking summary with:
	 * - Event title (linked to event page)
	 * - Participant name
	 * - Bike + size (if present)
	 * - Booking date
	 *
	 * Only renders for orders containing booking items (detected by _event_id meta).
	 *
	 * @param \WC_Order $order The order object
	 */
	public static function render_order_summary_block( $order ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		// Collect booking items from order
		$booking_items = self::get_booking_items_from_order( $order );

		if ( empty( $booking_items ) ) {
			return; // Not a booking order, leave Woo default alone
		}

		// Output styles
		self::output_summary_styles();

		echo '<div class="tcbf-order-summary">';
		echo '<h3 class="tcbf-order-summary-heading">' . esc_html__( 'Booking Details', TC_BF_TEXTDOMAIN ) . '</h3>';

		foreach ( $booking_items as $item_data ) {
			self::render_single_booking_summary( $item_data );
		}

		echo '</div>';
	}

	/**
	 * Render TCBF Summary block for emails.
	 *
	 * Same as order summary but with email-safe markup.
	 *
	 * @param \WC_Order $order The order object
	 * @param bool $sent_to_admin Whether email is sent to admin
	 * @param bool $plain_text Whether email is plain text
	 * @param \WC_Email $email The email object (optional)
	 */
	public static function render_email_summary_block( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( $plain_text ) {
			return; // Skip for plain text emails
		}

		// Collect booking items
		$booking_items = self::get_booking_items_from_order( $order );

		if ( empty( $booking_items ) ) {
			return;
		}

		// Email-safe inline styles
		echo '<div style="margin: 20px 0; padding: 16px 20px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fafafa;">';
		echo '<h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700;">' . esc_html__( 'Booking Details', TC_BF_TEXTDOMAIN ) . '</h3>';

		foreach ( $booking_items as $item_data ) {
			self::render_single_booking_summary_email( $item_data );
		}

		echo '</div>';
	}

	/**
	 * Render enhanced blocks for emails (with visibility rules).
	 *
	 * @param \WC_Order $order The order object
	 * @param bool $sent_to_admin Whether email is sent to admin
	 * @param bool $plain_text Whether email is plain text
	 * @param \WC_Email $email The email object (optional)
	 */
	public static function render_email_enhanced_blocks( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( $plain_text ) {
			return;
		}

		// Detect if this is a customer email (conservative: anything not admin = customer)
		$is_customer_email = true;
		if ( $email && is_object( $email ) && property_exists( $email, 'id' ) ) {
			$email_id = (string) $email->id;
			// Admin emails typically have 'admin_' prefix or are 'new_order'
			if ( strpos( $email_id, 'admin_' ) === 0 || $email_id === 'new_order' ) {
				$is_customer_email = false;
			}
		}
		// Also check sent_to_admin flag
		if ( $sent_to_admin ) {
			$is_customer_email = false;
		}

		$currency = $order->get_currency();

		// === Early Booking Discount Block ===
		$eb_amount = (float) $order->get_meta( 'early_booking_discount_amount', true );
		if ( $eb_amount > 0 ) {
			$eb_pct = (float) $order->get_meta( 'early_booking_discount_pct', true );
			$eb_sub = $eb_pct > 0
				? sprintf( __( '%s%% applied to your booking', TC_BF_TEXTDOMAIN ), number_format_i18n( $eb_pct, 0 ) )
				: __( 'Applied to your booking', TC_BF_TEXTDOMAIN );

			echo '<div style="margin: 14px 0; padding: 16px 20px; border-radius: 10px; background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%); display: flex; justify-content: space-between; align-items: center;">';
			echo '<div style="color: #fff;"><strong style="font-size: 15px;">' . esc_html__( 'Early booking discount', TC_BF_TEXTDOMAIN ) . '</strong><br><span style="opacity: 0.9; font-size: 13px;">' . esc_html( $eb_sub ) . '</span></div>';
			echo '<div style="color: #fff; font-weight: 800; font-size: 18px;">-' . wp_kses_post( strip_tags( wc_price( $eb_amount, [ 'currency' => $currency ] ), '<span>' ) ) . '</div>';
			echo '</div>';
		}

		// === Partner Discount Block ===
		$partner_id = (int) $order->get_meta( 'partner_id', true );
		if ( $partner_id > 0 ) {
			$discount_total = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
			if ( $discount_total > 0 ) {
				$partner_pct = (float) $order->get_meta( 'partner_discount_pct', true );
				$partner_sub = $partner_pct > 0
					? sprintf( __( '%s%% partner discount', TC_BF_TEXTDOMAIN ), number_format_i18n( $partner_pct, 0 ) )
					: __( 'Partner discount applied', TC_BF_TEXTDOMAIN );

				echo '<div style="margin: 14px 0; padding: 16px 20px; border-radius: 10px; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #bbf7d0; display: flex; justify-content: space-between; align-items: center;">';
				echo '<div style="color: #111827;"><strong style="font-size: 15px;">' . esc_html__( 'Partner discount', TC_BF_TEXTDOMAIN ) . '</strong><br><span style="opacity: 0.9; font-size: 13px;">' . esc_html( $partner_sub ) . '</span></div>';
				echo '<div style="color: #111827; font-weight: 800; font-size: 18px;">-' . wp_kses_post( strip_tags( wc_price( $discount_total, [ 'currency' => $currency ] ), '<span>' ) ) . '</div>';
				echo '</div>';
			}

			// === Commission Block (ADMIN EMAIL ONLY - never for customers) ===
			if ( ! $is_customer_email ) {
				$commission = (float) $order->get_meta( 'partner_commission', true );
				if ( $commission > 0 ) {
					$commission_rate = (float) $order->get_meta( 'partner_commission_rate', true );
					$comm_sub = $commission_rate > 0
						? sprintf( __( '%s%% of base total', TC_BF_TEXTDOMAIN ), number_format_i18n( $commission_rate, 0 ) )
						: __( 'Based on order total', TC_BF_TEXTDOMAIN );

					echo '<div style="margin: 14px 0; padding: 16px 20px; border-radius: 10px; background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%); border: 1px solid #c7d2fe; display: flex; justify-content: space-between; align-items: center;">';
					echo '<div style="color: #111827;"><strong style="font-size: 15px;">' . esc_html__( 'Partner commission', TC_BF_TEXTDOMAIN ) . '</strong><br><span style="opacity: 0.9; font-size: 13px;">' . esc_html( $comm_sub ) . '</span></div>';
					echo '<div style="color: #111827; font-weight: 800; font-size: 18px;">' . wp_kses_post( strip_tags( wc_price( $commission, [ 'currency' => $currency ] ), '<span>' ) ) . '</div>';
					echo '</div>';
				}
			}
		}
	}

	/**
	 * Get booking items from order (items with _event_id meta).
	 *
	 * @param \WC_Order $order
	 * @return array Array of item data arrays
	 */
	private static function get_booking_items_from_order( \WC_Order $order ) : array {
		$booking_items = [];

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$event_id = (int) $item->get_meta( '_event_id', true );
			if ( $event_id <= 0 ) {
				// Also check without underscore
				$event_id = (int) $item->get_meta( 'event_id', true );
			}

			if ( $event_id <= 0 ) {
				continue; // Not a booking item
			}

			// Get event title
			$event_title = (string) $item->get_meta( '_event_title', true );
			if ( $event_title === '' ) {
				$event_title = (string) $item->get_meta( 'event_title', true );
			}
			if ( $event_title === '' ) {
				$event_title = get_the_title( $event_id );
			}

			// Get event URL
			$event_url = get_permalink( $event_id );
			if ( ! $event_url ) {
				$product = $item->get_product();
				$event_url = $product ? $product->get_permalink() : '';
			}

			// Get participant
			$participant = (string) $item->get_meta( '_participant', true );
			if ( $participant === '' ) {
				$participant = (string) $item->get_meta( 'participant', true );
			}

			// Get bicycle
			$bicycle = (string) $item->get_meta( '_bicycle', true );
			if ( $bicycle === '' ) {
				$bicycle = (string) $item->get_meta( 'bicycle', true );
			}

			// Get scope (participation vs rental)
			$scope = (string) $item->get_meta( '_tc_scope', true );
			if ( $scope === '' ) {
				$scope = (string) $item->get_meta( 'tcbf_scope', true );
			}

			// Skip rental items (they're part of the pack, show only parent)
			if ( $scope === 'rental' ) {
				continue;
			}

			// Get booking date (if available via WooCommerce Bookings)
			$booking_date = '';
			if ( class_exists( 'WC_Booking_Data_Store' ) ) {
				$booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
				if ( ! empty( $booking_ids ) ) {
					$booking = new \WC_Booking( (int) $booking_ids[0] );
					if ( $booking && $booking->get_start() ) {
						$booking_date = date_i18n( get_option( 'date_format' ), $booking->get_start() );
					}
				}
			}

			// Get size from rental child (if exists)
			$size = self::get_rental_size_for_pack( $order, $item );

			$booking_items[] = [
				'item_id'       => $item_id,
				'event_id'      => $event_id,
				'event_title'   => $event_title,
				'event_url'     => $event_url,
				'participant'   => $participant,
				'bicycle'       => $bicycle,
				'size'          => $size,
				'booking_date'  => $booking_date,
				'scope'         => $scope,
			];
		}

		return $booking_items;
	}

	/**
	 * Get rental size for a participation item's pack.
	 *
	 * @param \WC_Order $order
	 * @param \WC_Order_Item_Product $parent_item
	 * @return string Size (e.g., "M", "L", "XL") or empty
	 */
	private static function get_rental_size_for_pack( \WC_Order $order, \WC_Order_Item_Product $parent_item ) : string {
		$parent_group_id = (int) $parent_item->get_meta( 'tc_group_id', true );
		if ( $parent_group_id <= 0 ) {
			return '';
		}

		// Find matching rental item in the same group
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$group_id = (int) $item->get_meta( 'tc_group_id', true );
			$scope    = (string) $item->get_meta( '_tc_scope', true );
			if ( $scope === '' ) {
				$scope = (string) $item->get_meta( 'tcbf_scope', true );
			}

			if ( $group_id === $parent_group_id && $scope === 'rental' ) {
				// Found rental child - get size from booking resource
				if ( class_exists( 'WC_Booking_Data_Store' ) ) {
					$booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
					if ( ! empty( $booking_ids ) ) {
						$booking  = new \WC_Booking( (int) $booking_ids[0] );
						$resource = $booking ? $booking->get_resource() : null;
						if ( $resource && is_object( $resource ) ) {
							$resource_name = $resource->get_name();
							// Extract size token (S, M, L, XL, XXL)
							if ( preg_match( '/\b(XXL|XL|[SMLX])\b/i', $resource_name, $matches ) ) {
								return strtoupper( $matches[1] );
							}
							return $resource_name;
						}
					}
				}
			}
		}

		return '';
	}

	/**
	 * Render a single booking summary card (frontend).
	 *
	 * @param array $item_data Booking item data
	 */
	private static function render_single_booking_summary( array $item_data ) : void {
		echo '<div class="tcbf-summary-card">';

		// Event title (linked)
		if ( $item_data['event_title'] !== '' ) {
			echo '<div class="tcbf-summary-row tcbf-summary-event">';
			echo '<span class="tcbf-summary-label">' . esc_html__( 'Tour', TC_BF_TEXTDOMAIN ) . '</span>';
			if ( $item_data['event_url'] ) {
				echo '<a href="' . esc_url( $item_data['event_url'] ) . '" class="tcbf-summary-value tcbf-event-link">' . esc_html( $item_data['event_title'] ) . '</a>';
			} else {
				echo '<span class="tcbf-summary-value">' . esc_html( $item_data['event_title'] ) . '</span>';
			}
			echo '</div>';
		}

		// Date
		if ( $item_data['booking_date'] !== '' ) {
			echo '<div class="tcbf-summary-row">';
			echo '<span class="tcbf-summary-label">' . esc_html__( 'Date', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '<span class="tcbf-summary-value">' . esc_html( $item_data['booking_date'] ) . '</span>';
			echo '</div>';
		}

		// Participant
		if ( $item_data['participant'] !== '' ) {
			echo '<div class="tcbf-summary-row">';
			echo '<span class="tcbf-summary-label">' . esc_html__( 'Participant', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '<span class="tcbf-summary-value">' . esc_html( $item_data['participant'] ) . '</span>';
			echo '</div>';
		}

		// Bike + Size
		if ( $item_data['bicycle'] !== '' ) {
			echo '<div class="tcbf-summary-row">';
			echo '<span class="tcbf-summary-label">' . esc_html__( 'Bike', TC_BF_TEXTDOMAIN ) . '</span>';
			$bike_text = $item_data['bicycle'];
			if ( $item_data['size'] !== '' ) {
				$bike_text .= ' (' . __( 'Size', TC_BF_TEXTDOMAIN ) . ': ' . $item_data['size'] . ')';
			}
			echo '<span class="tcbf-summary-value">' . esc_html( $bike_text ) . '</span>';
			echo '</div>';
		} elseif ( $item_data['size'] !== '' ) {
			// Size without bike name
			echo '<div class="tcbf-summary-row">';
			echo '<span class="tcbf-summary-label">' . esc_html__( 'Size', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '<span class="tcbf-summary-value">' . esc_html( $item_data['size'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Render a single booking summary for emails (inline styles).
	 *
	 * @param array $item_data Booking item data
	 */
	private static function render_single_booking_summary_email( array $item_data ) : void {
		echo '<table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">';

		// Event title
		if ( $item_data['event_title'] !== '' ) {
			echo '<tr>';
			echo '<td style="padding: 4px 0; color: #6b7280; font-size: 13px; width: 100px;">' . esc_html__( 'Tour', TC_BF_TEXTDOMAIN ) . '</td>';
			if ( $item_data['event_url'] ) {
				echo '<td style="padding: 4px 0; font-weight: 600;"><a href="' . esc_url( $item_data['event_url'] ) . '" style="color: #3d61aa; text-decoration: none;">' . esc_html( $item_data['event_title'] ) . '</a></td>';
			} else {
				echo '<td style="padding: 4px 0; font-weight: 600;">' . esc_html( $item_data['event_title'] ) . '</td>';
			}
			echo '</tr>';
		}

		// Date
		if ( $item_data['booking_date'] !== '' ) {
			echo '<tr>';
			echo '<td style="padding: 4px 0; color: #6b7280; font-size: 13px;">' . esc_html__( 'Date', TC_BF_TEXTDOMAIN ) . '</td>';
			echo '<td style="padding: 4px 0;">' . esc_html( $item_data['booking_date'] ) . '</td>';
			echo '</tr>';
		}

		// Participant
		if ( $item_data['participant'] !== '' ) {
			echo '<tr>';
			echo '<td style="padding: 4px 0; color: #6b7280; font-size: 13px;">' . esc_html__( 'Participant', TC_BF_TEXTDOMAIN ) . '</td>';
			echo '<td style="padding: 4px 0;">' . esc_html( $item_data['participant'] ) . '</td>';
			echo '</tr>';
		}

		// Bike + Size
		if ( $item_data['bicycle'] !== '' || $item_data['size'] !== '' ) {
			echo '<tr>';
			echo '<td style="padding: 4px 0; color: #6b7280; font-size: 13px;">' . esc_html__( 'Bike', TC_BF_TEXTDOMAIN ) . '</td>';
			$bike_text = $item_data['bicycle'] !== '' ? $item_data['bicycle'] : '';
			if ( $item_data['size'] !== '' ) {
				$bike_text .= $bike_text !== '' ? ' (' . $item_data['size'] . ')' : $item_data['size'];
			}
			echo '<td style="padding: 4px 0;">' . esc_html( $bike_text ) . '</td>';
			echo '</tr>';
		}

		echo '</table>';
	}

	/**
	 * Output CSS styles for summary block (only once per page).
	 */
	private static function output_summary_styles() : void {
		static $output = false;
		if ( $output ) return;
		$output = true;

		?>
		<style>
		/* TCBF Order Summary Block */
		.tcbf-order-summary {
			margin: 0 0 24px;
		}
		.tcbf-order-summary-heading {
			font-size: 18px;
			font-weight: 700;
			margin: 0 0 16px;
		}
		.tcbf-summary-card {
			background: transparent;
			border: 1px solid #e5e7eb;
			border-radius: 10px;
			padding: 16px 20px;
			margin-bottom: 12px;
		}
		.tcbf-summary-row {
			display: flex;
			justify-content: space-between;
			align-items: baseline;
			padding: 6px 0;
			border-bottom: 1px solid #f3f4f6;
		}
		.tcbf-summary-row:last-child {
			border-bottom: none;
		}
		.tcbf-summary-label {
			color: #6b7280;
			font-size: 13px;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			flex-shrink: 0;
			margin-right: 16px;
		}
		.tcbf-summary-value {
			font-weight: 500;
			color: #111827;
			text-align: right;
		}
		.tcbf-event-link {
			color: #3d61aa;
			text-decoration: none;
		}
		.tcbf-event-link:hover {
			text-decoration: underline;
		}
		.tcbf-summary-event .tcbf-summary-value,
		.tcbf-summary-event .tcbf-event-link {
			font-weight: 700;
			font-size: 15px;
		}
		</style>
		<?php
	}

	/* =========================================================
	 * Grouped Order Items Table (Cart-like display)
	 * ========================================================= */

	/**
	 * Check if an order contains booking items (has _event_id or tc_group_id on any line item).
	 *
	 * Used to determine whether to use grouped renderer or default Woo loop.
	 *
	 * @param \WC_Order $order The order object
	 * @return bool True if order contains booking items
	 */
	public static function is_booking_order( \WC_Order $order ) : bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			// Check for _event_id (primary booking marker)
			$event_id = self::get_item_meta_ci( $item, '_event_id' );
			if ( $event_id !== '' && (int) $event_id > 0 ) {
				return true;
			}

			// Check for tc_group_id (pack grouping marker)
			$group_id = self::get_item_meta_ci( $item, 'tc_group_id' );
			if ( $group_id !== '' && (int) $group_id > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get item meta case-insensitively (tries with/without underscore, upper/lower).
	 *
	 * @param \WC_Order_Item_Product $item The order item
	 * @param string $key The meta key to look for
	 * @return string The meta value or empty string
	 */
	private static function get_item_meta_ci( \WC_Order_Item_Product $item, string $key ) : string {
		// Try exact key
		$value = $item->get_meta( $key, true );
		if ( $value !== '' ) {
			return (string) $value;
		}

		// Try without leading underscore
		$key_no_underscore = ltrim( $key, '_' );
		$value = $item->get_meta( $key_no_underscore, true );
		if ( $value !== '' ) {
			return (string) $value;
		}

		// Try with underscore if not present
		if ( strpos( $key, '_' ) !== 0 ) {
			$value = $item->get_meta( '_' . $key, true );
			if ( $value !== '' ) {
				return (string) $value;
			}
		}

		// Try uppercase variants
		$key_upper = strtoupper( $key_no_underscore );
		$value = $item->get_meta( $key_upper, true );
		if ( $value !== '' ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Get event featured image URL.
	 *
	 * @param int $event_id The event (post) ID
	 * @param string $size Image size (default: 'woocommerce_thumbnail')
	 * @return string Image URL or empty string
	 */
	public static function get_event_image_url( int $event_id, string $size = 'woocommerce_thumbnail' ) : string {
		if ( $event_id <= 0 ) {
			return '';
		}

		$thumb_id = get_post_thumbnail_id( $event_id );
		if ( ! $thumb_id ) {
			return '';
		}

		$url = wp_get_attachment_image_url( $thumb_id, $size );
		return $url ? (string) $url : '';
	}

	/**
	 * Render grouped order items table (cart-like parent/child display).
	 *
	 * Groups items by tc_group_id, renders parent rows with event thumbnail,
	 * child rows indented with product thumbnail.
	 *
	 * @param \WC_Order $order The order object
	 */
	public static function render_grouped_order_items_table( \WC_Order $order ) : void {
		$items = $order->get_items( 'line_item' );

		if ( empty( $items ) ) {
			return;
		}

		// Build item records with normalized metadata
		$records = [];
		foreach ( $items as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$records[] = self::build_item_record( $order, $item_id, $item );
		}

		// Group by tc_group_id
		$groups = [];
		$standalone = [];

		foreach ( $records as $record ) {
			if ( $record['group_id'] > 0 ) {
				$gid = $record['group_id'];
				if ( ! isset( $groups[ $gid ] ) ) {
					$groups[ $gid ] = [];
				}
				$groups[ $gid ][] = $record;
			} else {
				$standalone[] = $record;
			}
		}

		// Output styles
		self::output_grouped_items_styles();

		// Render grouped items
		echo '<div class="tcbf-order-items">';

		foreach ( $groups as $group_id => $group_items ) {
			self::render_group( $order, $group_items );
		}

		// Render standalone items (non-booking products)
		foreach ( $standalone as $record ) {
			self::render_standalone_row( $order, $record );
		}

		echo '</div>';
	}

	/**
	 * Build a normalized item record from order item.
	 *
	 * @param \WC_Order $order The order
	 * @param int $item_id The item ID
	 * @param \WC_Order_Item_Product $item The item
	 * @return array Item record with normalized fields
	 */
	private static function build_item_record( \WC_Order $order, int $item_id, \WC_Order_Item_Product $item ) : array {
		$product = $item->get_product();

		// Get group metadata (case-insensitive)
		$group_id = (int) self::get_item_meta_ci( $item, 'tc_group_id' );
		$role     = self::get_item_meta_ci( $item, 'tc_group_role' );
		$scope    = self::get_item_meta_ci( $item, 'tcbf_scope' );

		// Fallback: also check _tc_scope
		if ( $scope === '' ) {
			$scope = self::get_item_meta_ci( $item, '_tc_scope' );
		}

		// Get event data
		$event_id    = (int) self::get_item_meta_ci( $item, '_event_id' );
		$event_title = self::get_item_meta_ci( $item, '_event_title' );

		// Fallback event title
		if ( $event_title === '' && $event_id > 0 ) {
			$event_title = get_the_title( $event_id );
		}

		// Get participant/bike data
		$participant = self::get_item_meta_ci( $item, '_participant' );
		if ( $participant === '' ) {
			$participant = self::get_item_meta_ci( $item, 'participant' );
		}

		$bicycle = self::get_item_meta_ci( $item, '_bicycle' );
		if ( $bicycle === '' ) {
			$bicycle = self::get_item_meta_ci( $item, 'bicycle' );
		}

		// Get size from booking resource if available
		$size = self::get_booking_size_from_item( $item_id );

		// Get product info
		$product_name = $item->get_name();
		$product_url  = $product ? $product->get_permalink() : '';
		$product_thumb_url = '';
		if ( $product ) {
			$thumb_id = $product->get_image_id();
			if ( $thumb_id ) {
				$product_thumb_url = wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' );
			}
		}

		// Get event URL
		$event_url = $event_id > 0 ? get_permalink( $event_id ) : '';

		// Get booking date
		$booking_date = self::get_booking_date_from_item( $item_id );

		// Get EB (Early Booking) meta
		$eb_eligible = (int) self::get_item_meta_ci( $item, '_eb_eligible' );
		$eb_pct      = (float) self::get_item_meta_ci( $item, '_eb_pct' );
		$eb_amount   = (float) self::get_item_meta_ci( $item, '_eb_amount' );
		$eb_base     = (float) self::get_item_meta_ci( $item, '_eb_base_price' );

		// Calculate EB amount if not stored but we have pct and base
		if ( $eb_eligible && $eb_amount <= 0 && $eb_pct > 0 && $eb_base > 0 ) {
			$eb_amount = $eb_base * ( $eb_pct / 100 );
		}

		return [
			'item_id'           => $item_id,
			'item'              => $item,
			'group_id'          => $group_id,
			'role'              => $role,
			'scope'             => $scope,
			'event_id'          => $event_id,
			'event_title'       => $event_title,
			'event_url'         => $event_url,
			'participant'       => $participant,
			'bicycle'           => $bicycle,
			'size'              => $size,
			'booking_date'      => $booking_date,
			'product_name'      => $product_name,
			'product_url'       => $product_url,
			'product_thumb_url' => $product_thumb_url ?: '',
			'product'           => $product,
			'eb_eligible'       => $eb_eligible,
			'eb_pct'            => $eb_pct,
			'eb_amount'         => $eb_amount,
			'eb_base'           => $eb_base,
		];
	}

	/**
	 * Get booking size (resource name) from order item.
	 *
	 * @param int $item_id Order item ID
	 * @return string Size string or empty
	 */
	private static function get_booking_size_from_item( int $item_id ) : string {
		if ( ! class_exists( 'WC_Booking_Data_Store' ) ) {
			return '';
		}

		$booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
		if ( empty( $booking_ids ) ) {
			return '';
		}

		$booking = new \WC_Booking( (int) $booking_ids[0] );
		$resource = $booking ? $booking->get_resource() : null;

		if ( $resource && is_object( $resource ) ) {
			$resource_name = $resource->get_name();
			// Extract size token (S, M, L, XL, XXL)
			if ( preg_match( '/\b(XXL|XL|[SMLX]{1,2})\b/i', $resource_name, $matches ) ) {
				return strtoupper( $matches[1] );
			}
			return $resource_name;
		}

		return '';
	}

	/**
	 * Get booking date from order item.
	 *
	 * @param int $item_id Order item ID
	 * @return string Formatted date or empty
	 */
	private static function get_booking_date_from_item( int $item_id ) : string {
		if ( ! class_exists( 'WC_Booking_Data_Store' ) ) {
			return '';
		}

		$booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
		if ( empty( $booking_ids ) ) {
			return '';
		}

		$booking = new \WC_Booking( (int) $booking_ids[0] );
		if ( $booking && $booking->get_start() ) {
			return date_i18n( get_option( 'date_format' ), $booking->get_start() );
		}

		return '';
	}

	/**
	 * Calculate pack totals for a group of items.
	 *
	 * Uses meta-first approach: prefer stored EB snapshots, fallback to Woo line subtotals.
	 * Labels defensively: "Pack price before EB" only if base is guaranteed from meta.
	 *
	 * @param \WC_Order $order The order object
	 * @param array $group_items Array of item records in this group
	 * @return array Pack totals data
	 */
	private static function calculate_pack_totals( \WC_Order $order, array $group_items ) : array {
		$pack_eb_discount = 0.0;
		$pack_base_price  = 0.0;
		$has_eb           = false;
		$base_from_meta   = true; // Track if we have authoritative base prices

		// Tax display mode for order context
		$inc_tax = ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) );

		foreach ( $group_items as $record ) {
			$item = $record['item'];
			$qty  = max( 1, (int) $item->get_quantity() );

			// EB discount: use stored meta amount (per unit * qty)
			if ( $record['eb_eligible'] && $record['eb_amount'] > 0 ) {
				$pack_eb_discount += $record['eb_amount'] * $qty;
				$has_eb = true;
			}

			// Base price: prefer stored _eb_base_price (per unit * qty)
			if ( $record['eb_base'] > 0 ) {
				$pack_base_price += $record['eb_base'] * $qty;
			} else {
				// Fallback to Woo line subtotal (before discounts)
				$pack_base_price += (float) $order->get_line_subtotal( $item, $inc_tax );
				$base_from_meta = false;
			}
		}

		// Calculate pack total (after EB)
		$pack_total = $pack_base_price - $pack_eb_discount;

		// Determine safe label for base price
		// Only say "before EB" if we have authoritative base from meta AND there is EB
		$base_label = ( $base_from_meta && $has_eb )
			? __( 'Pack price before EB', TC_BF_TEXTDOMAIN )
			: __( 'Pack price', TC_BF_TEXTDOMAIN );

		return [
			'base_price'  => $pack_base_price,
			'base_label'  => $base_label,
			'eb_discount' => $pack_eb_discount,
			'pack_total'  => $pack_total,
			'has_eb'      => $has_eb,
			'inc_tax'     => $inc_tax,
		];
	}

	/**
	 * Render pack pricing footer inside pack group.
	 *
	 * Shows: base price, EB discount (if applicable), pack total.
	 *
	 * @param array $pack_totals Pack totals from calculate_pack_totals()
	 */
	private static function render_pack_footer( array $pack_totals ) : void {
		// Only show footer if there's EB or if explicitly requested
		// For now, always show if EB exists; otherwise skip footer
		if ( ! $pack_totals['has_eb'] ) {
			return;
		}

		echo '<div class="tcbf-pack-footer">';

		// Base price line
		echo '<div class="tcbf-pack-footer-line tcbf-pack-footer-base">';
		echo '<span class="tcbf-pack-footer-label">' . esc_html( $pack_totals['base_label'] ) . '</span>';
		echo '<span class="tcbf-pack-footer-value">' . wp_kses_post( wc_price( $pack_totals['base_price'] ) ) . '</span>';
		echo '</div>';

		// EB discount line (only if has EB)
		if ( $pack_totals['has_eb'] && $pack_totals['eb_discount'] > 0 ) {
			echo '<div class="tcbf-pack-footer-line tcbf-pack-footer-eb">';
			echo '<span class="tcbf-pack-footer-label">' . esc_html__( 'Early booking discount', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '<span class="tcbf-pack-footer-value tcbf-pack-footer-discount">-' . wp_kses_post( wc_price( $pack_totals['eb_discount'] ) ) . '</span>';
			echo '</div>';
		}

		// Pack total line
		echo '<div class="tcbf-pack-footer-line tcbf-pack-footer-total">';
		echo '<span class="tcbf-pack-footer-label">' . esc_html__( 'Pack total', TC_BF_TEXTDOMAIN ) . '</span>';
		echo '<span class="tcbf-pack-footer-value">' . wp_kses_post( wc_price( $pack_totals['pack_total'] ) ) . '</span>';
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Calculate pack totals for cart items.
	 *
	 * Public method for use in cart/checkout templates.
	 *
	 * @param array $cart_items Array of cart items in this pack group
	 * @return array Pack totals data
	 */
	public static function calculate_cart_pack_totals( array $cart_items ) : array {
		$pack_eb_discount = 0.0;
		$pack_base_price  = 0.0;
		$has_eb           = false;
		$base_from_meta   = true;

		foreach ( $cart_items as $cart_item ) {
			$qty = max( 1, (int) $cart_item['quantity'] );

			// EB discount from cart item data
			$eb_eligible = isset( $cart_item['tcbf_eb_eligible'] ) ? (int) $cart_item['tcbf_eb_eligible'] : 0;
			$eb_amount   = isset( $cart_item['tcbf_eb_amount'] ) ? (float) $cart_item['tcbf_eb_amount'] : 0.0;
			$eb_base     = isset( $cart_item['tcbf_eb_base'] ) ? (float) $cart_item['tcbf_eb_base'] : 0.0;

			if ( $eb_eligible && $eb_amount > 0 ) {
				$pack_eb_discount += $eb_amount * $qty;
				$has_eb = true;
			}

			// Base price from meta or line subtotal
			if ( $eb_base > 0 ) {
				$pack_base_price += $eb_base * $qty;
			} else {
				// Fallback to cart line subtotal
				$pack_base_price += (float) $cart_item['line_subtotal'];
				if ( 'incl' === get_option( 'woocommerce_tax_display_cart' ) ) {
					$pack_base_price += (float) ( $cart_item['line_subtotal_tax'] ?? 0 );
				}
				$base_from_meta = false;
			}
		}

		$pack_total = $pack_base_price - $pack_eb_discount;

		$base_label = ( $base_from_meta && $has_eb )
			? __( 'Pack price before EB', TC_BF_TEXTDOMAIN )
			: __( 'Pack price', TC_BF_TEXTDOMAIN );

		return [
			'base_price'  => $pack_base_price,
			'base_label'  => $base_label,
			'eb_discount' => $pack_eb_discount,
			'pack_total'  => $pack_total,
			'has_eb'      => $has_eb,
		];
	}

	/**
	 * Group cart items by tc_group_id.
	 *
	 * @return array Grouped cart items: [ group_id => [ cart_key => cart_item, ... ], ... ]
	 */
	public static function group_cart_items() : array {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return [];
		}

		$groups = [];
		$ungrouped = [];

		foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
			$group_id = isset( $cart_item['tc_group_id'] ) ? (int) $cart_item['tc_group_id'] : 0;

			if ( $group_id > 0 ) {
				if ( ! isset( $groups[ $group_id ] ) ) {
					$groups[ $group_id ] = [];
				}
				$groups[ $group_id ][ $cart_key ] = $cart_item;
			} else {
				$ungrouped[ $cart_key ] = $cart_item;
			}
		}

		// Add ungrouped items as individual "groups" with key 0_n
		$idx = 0;
		foreach ( $ungrouped as $cart_key => $cart_item ) {
			$groups[ '0_' . $idx ] = [ $cart_key => $cart_item ];
			$idx++;
		}

		return $groups;
	}

	/**
	 * Check if cart item is a parent (participation/tour).
	 *
	 * @param array $cart_item Cart item data
	 * @return bool True if parent
	 */
	public static function is_cart_item_parent( array $cart_item ) : bool {
		$role = $cart_item['tc_group_role'] ?? '';
		if ( $role === 'parent' ) {
			return true;
		}

		$scope = $cart_item['tcbf_scope'] ?? ( $cart_item['_tc_scope'] ?? '' );
		if ( $scope === 'participation' ) {
			return true;
		}

		$event_id = isset( $cart_item['_event_id'] ) ? (int) $cart_item['_event_id'] : 0;
		if ( $event_id > 0 && $scope !== 'rental' ) {
			return true;
		}

		return false;
	}

	/**
	 * Render pack footer (public wrapper).
	 *
	 * @param array $pack_totals Pack totals data
	 */
	public static function output_pack_footer( array $pack_totals ) : void {
		self::render_pack_footer( $pack_totals );
	}

	/**
	 * Output pack styles for cart/checkout context.
	 *
	 * Outputs CSS once per page load.
	 */
	public static function output_pack_styles_once() : void {
		static $output = false;
		if ( $output ) {
			return;
		}
		$output = true;

		self::output_grouped_items_styles();
	}

	/**
	 * Render a group of items (parent + children).
	 *
	 * @param \WC_Order $order The order
	 * @param array $group_items Array of item records in this group
	 */
	private static function render_group( \WC_Order $order, array $group_items ) : void {
		// Identify parent and children
		$parent = null;
		$children = [];

		foreach ( $group_items as $record ) {
			$is_parent = false;

			// Check role first
			if ( $record['role'] === 'parent' ) {
				$is_parent = true;
			}
			// Fallback: scope = participation means parent
			elseif ( $record['scope'] === 'participation' ) {
				$is_parent = true;
			}
			// Fallback: has event_id and not rental scope
			elseif ( $record['event_id'] > 0 && $record['scope'] !== 'rental' ) {
				$is_parent = true;
			}

			if ( $is_parent && $parent === null ) {
				$parent = $record;
			} else {
				$children[] = $record;
			}
		}

		// If no parent identified, use first item as parent
		if ( $parent === null && ! empty( $group_items ) ) {
			$parent = array_shift( $group_items );
			$children = $group_items;
		}

		// Detect if group has rental child (for "Bicicleta: Propia" logic)
		$has_rental = false;
		foreach ( $children as $child ) {
			if ( $child['scope'] === 'rental' || $child['role'] === 'child' ) {
				$has_rental = true;
				break;
			}
		}

		// Open pack group container
		echo '<div class="tcbf-pack-group">';

		// Render parent row (pass has_rental flag)
		if ( $parent ) {
			self::render_parent_row( $order, $parent, $has_rental );
		}

		// Render child rows
		foreach ( $children as $child ) {
			self::render_child_row( $order, $child );
		}

		// Calculate and render pack totals footer
		$pack_totals = self::calculate_pack_totals( $order, $group_items );
		self::render_pack_footer( $pack_totals );

		// Close pack group container
		echo '</div>';
	}

	/**
	 * Render a parent (tour/participation) row.
	 *
	 * @param \WC_Order $order The order
	 * @param array $record Item record
	 * @param bool $has_rental Whether this group has a rental child
	 */
	private static function render_parent_row( \WC_Order $order, array $record, bool $has_rental = false ) : void {
		$item = $record['item'];

		// Thumbnail: event featured image → product thumb → placeholder
		$thumb_url = '';
		if ( $record['event_id'] > 0 ) {
			$thumb_url = self::get_event_image_url( $record['event_id'] );
		}
		if ( $thumb_url === '' ) {
			$thumb_url = $record['product_thumb_url'];
		}

		// Title: product name (not event title - event shown separately)
		$title = $record['product_name'];
		$title_url = $record['event_url'] ?: $record['product_url'];

		// Price: use Woo formatted line subtotal
		$price_html = $order->get_formatted_line_subtotal( $item );

		// Check if viewer can see participant status badge (admin or partner-owner)
		$show_participant_badge = self::can_viewer_see_participant_badge( $order );

		echo '<div class="tcbf-order-row tcbf-order-row--parent">';

		// Thumbnail
		echo '<div class="tcbf-order-thumb">';
		if ( $thumb_url ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" />';
		} else {
			echo '<span class="tcbf-order-thumb--placeholder"></span>';
		}
		echo '</div>';

		// Content
		echo '<div class="tcbf-order-content">';

		// Title (product name)
		echo '<div class="tcbf-order-title">';
		if ( $title_url ) {
			echo '<a href="' . esc_url( $title_url ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '</div>';

		// Participant line with badge
		if ( $record['participant'] !== '' ) {
			echo '<div class="tcbf-participant-line">';
			echo '<span class="tcbf-participant-badge">';
			echo '<span class="tcbf-participant-icon">👤</span>';
			echo '<span class="tcbf-participant-name">' . esc_html( $record['participant'] ) . '</span>';
			echo '</span>';

			// Status badge (X/V) for admin or partner-owner
			if ( $show_participant_badge ) {
				$status_icon = self::get_participant_status_badge( $order, $record );
				if ( $status_icon !== '' ) {
					echo $status_icon;
				}
			}

			echo '</div>';
		}

		// EB badge line (if applicable)
		if ( $record['eb_eligible'] && ( $record['eb_pct'] > 0 || $record['eb_amount'] > 0 ) ) {
			echo '<div class="tcbf-eb-line">';
			echo '<span class="tcbf-eb-badge">';
			echo '<span class="tcbf-eb-icon">⏰</span>';
			if ( $record['eb_pct'] > 0 ) {
				echo '<span class="tcbf-eb-pct">' . esc_html( number_format_i18n( $record['eb_pct'], 0 ) ) . '%</span>';
				echo '<span class="tcbf-eb-sep">|</span>';
			}
			echo '<span class="tcbf-eb-amt">' . wp_kses_post( wc_price( $record['eb_amount'] ) ) . '</span>';
			echo '<span class="tcbf-eb-label">' . esc_html__( 'EB discount', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '</span>';
			echo '</div>';
		}

		// Meta lines
		echo '<div class="tcbf-order-meta-lines">';

		// Fecha de la reserva
		if ( $record['booking_date'] !== '' ) {
			echo '<div class="tcbf-meta-line">';
			echo '<span class="tcbf-meta-label">' . esc_html__( 'Booking date', TC_BF_TEXTDOMAIN ) . ':</span>';
			echo '<span class="tcbf-meta-value">' . esc_html( $record['booking_date'] ) . '</span>';
			echo '</div>';
		}

		// Evento (event title)
		if ( $record['event_title'] !== '' ) {
			echo '<div class="tcbf-meta-line">';
			echo '<span class="tcbf-meta-label">' . esc_html__( 'Event', TC_BF_TEXTDOMAIN ) . ':</span>';
			if ( $record['event_url'] ) {
				echo '<a href="' . esc_url( $record['event_url'] ) . '" class="tcbf-meta-value tcbf-meta-link">' . esc_html( $record['event_title'] ) . '</a>';
			} else {
				echo '<span class="tcbf-meta-value">' . esc_html( $record['event_title'] ) . '</span>';
			}
			echo '</div>';
		}

		// Bicicleta: Propia (only if no rental child)
		if ( ! $has_rental ) {
			echo '<div class="tcbf-meta-line">';
			echo '<span class="tcbf-meta-label">' . esc_html__( 'Bike', TC_BF_TEXTDOMAIN ) . ':</span>';
			echo '<span class="tcbf-meta-value">' . esc_html__( 'Own', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '</div>';
		}

		echo '</div>'; // .tcbf-order-meta-lines

		echo '</div>'; // .tcbf-order-content

		// Price
		echo '<div class="tcbf-order-price">' . wp_kses_post( $price_html ) . '</div>';

		echo '</div>'; // .tcbf-order-row
	}

	/**
	 * Check if viewer can see participant status badge.
	 *
	 * @param \WC_Order $order The order
	 * @return bool
	 */
	private static function can_viewer_see_participant_badge( \WC_Order $order ) : bool {
		$viewer_id = get_current_user_id();

		// Admin can always see
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		// Partner can see if they own the order
		$partner_id = (int) $order->get_meta( 'partner_id', true );
		if ( $partner_id > 0 && $viewer_id === $partner_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Get participant status badge HTML (checkmark or X).
	 *
	 * @param \WC_Order $order The order
	 * @param array $record Item record
	 * @return string Badge HTML or empty
	 */
	private static function get_participant_status_badge( \WC_Order $order, array $record ) : string {
		// For now, show checkmark if order is completed/processing, X otherwise
		$status = $order->get_status();
		$is_positive = in_array( $status, [ 'completed', 'processing' ], true );

		if ( $is_positive ) {
			return '<span class="tcbf-status-badge tcbf-status-badge--ok" title="' . esc_attr__( 'Confirmed', TC_BF_TEXTDOMAIN ) . '">✓</span>';
		} else {
			return '<span class="tcbf-status-badge tcbf-status-badge--pending" title="' . esc_attr__( 'Pending', TC_BF_TEXTDOMAIN ) . '">⏳</span>';
		}
	}

	/**
	 * Render a child (rental) row.
	 *
	 * @param \WC_Order $order The order
	 * @param array $record Item record
	 */
	private static function render_child_row( \WC_Order $order, array $record ) : void {
		$item = $record['item'];

		// Thumbnail: rental product thumb (NOT event image for child)
		$thumb_url = $record['product_thumb_url'];

		// Title: product name linked to product
		$title = $record['product_name'];
		$title_url = $record['product_url'];

		// Price: use Woo formatted line subtotal
		$price_html = $order->get_formatted_line_subtotal( $item );

		echo '<div class="tcbf-order-row tcbf-order-row--child">';

		// Thumbnail
		echo '<div class="tcbf-order-thumb">';
		if ( $thumb_url ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" />';
		} else {
			echo '<span class="tcbf-order-thumb--placeholder"></span>';
		}
		echo '</div>';

		// Content
		echo '<div class="tcbf-order-content">';

		// Title
		echo '<div class="tcbf-order-title">';
		if ( $title_url ) {
			echo '<a href="' . esc_url( $title_url ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '</div>';

		// "Rental included in the pack" badge line
		echo '<div class="tcbf-rental-badge-line">';
		echo '<span class="tcbf-badge-included">🚲 ' . esc_html__( 'Rental included in the pack', TC_BF_TEXTDOMAIN ) . '</span>';
		echo '</div>';

		// EB badge line for child (if applicable)
		if ( $record['eb_eligible'] && ( $record['eb_pct'] > 0 || $record['eb_amount'] > 0 ) ) {
			echo '<div class="tcbf-eb-line">';
			echo '<span class="tcbf-eb-badge">';
			echo '<span class="tcbf-eb-icon">⏰</span>';
			if ( $record['eb_pct'] > 0 ) {
				echo '<span class="tcbf-eb-pct">' . esc_html( number_format_i18n( $record['eb_pct'], 0 ) ) . '%</span>';
				echo '<span class="tcbf-eb-sep">|</span>';
			}
			echo '<span class="tcbf-eb-amt">' . wp_kses_post( wc_price( $record['eb_amount'] ) ) . '</span>';
			echo '<span class="tcbf-eb-label">' . esc_html__( 'EB discount', TC_BF_TEXTDOMAIN ) . '</span>';
			echo '</span>';
			echo '</div>';
		}

		// Talla line
		if ( $record['size'] !== '' ) {
			echo '<div class="tcbf-meta-line tcbf-talla-line">';
			echo '<span class="tcbf-meta-label">' . esc_html__( 'Size', TC_BF_TEXTDOMAIN ) . ':</span>';
			echo '<span class="tcbf-meta-value tcbf-talla-value">' . esc_html( $record['size'] ) . '</span>';
			echo '</div>';
		}

		echo '</div>'; // .tcbf-order-content

		// Price
		echo '<div class="tcbf-order-price">' . wp_kses_post( $price_html ) . '</div>';

		echo '</div>'; // .tcbf-order-row
	}

	/**
	 * Render a standalone (non-booking) row.
	 *
	 * @param \WC_Order $order The order
	 * @param array $record Item record
	 */
	private static function render_standalone_row( \WC_Order $order, array $record ) : void {
		$item = $record['item'];

		// Thumbnail: product thumb
		$thumb_url = $record['product_thumb_url'];

		// Title: product name linked to product
		$title = $record['product_name'];
		$title_url = $record['product_url'];

		// Price: use Woo formatted line subtotal
		$price_html = $order->get_formatted_line_subtotal( $item );

		// Quantity
		$qty = $item->get_quantity();

		echo '<div class="tcbf-order-row tcbf-order-row--standalone">';

		// Thumbnail
		echo '<div class="tcbf-order-thumb">';
		if ( $thumb_url ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" />';
		} else {
			echo '<span class="tcbf-order-thumb--placeholder"></span>';
		}
		echo '</div>';

		// Content
		echo '<div class="tcbf-order-content">';

		// Title
		echo '<div class="tcbf-order-title">';
		if ( $title_url ) {
			echo '<a href="' . esc_url( $title_url ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		if ( $qty > 1 ) {
			echo ' <span class="tcbf-qty">&times;&nbsp;' . esc_html( $qty ) . '</span>';
		}
		echo '</div>';

		echo '</div>'; // .tcbf-order-content

		// Price
		echo '<div class="tcbf-order-price">' . wp_kses_post( $price_html ) . '</div>';

		echo '</div>'; // .tcbf-order-row
	}

	/**
	 * Output CSS styles for grouped order items (only once per page).
	 * Matches cart pack UI styling for visual consistency.
	 */
	private static function output_grouped_items_styles() : void {
		static $output = false;
		if ( $output ) return;
		$output = true;

		?>
		<style>
		/* ===== TCBF Grouped Order Items - Cart-like Pack UI ===== */

		/* Theme color variable (inherits from theme or fallback) */
		:root {
			--tcbf-accent: var(--shopkeeper-accent, var(--theme-accent, var(--theme-primary-color, #434c00)));
		}

		/* Order items container */
		.tcbf-order-items {
			margin: 0 0 24px;
		}

		/* Pack group wrapper - visual grouping like cart */
		.tcbf-pack-group {
			background: rgba(255, 255, 255, 0.6);
			margin-bottom: 18px;
		}
		.tcbf-pack-group:last-child {
			margin-bottom: 0;
		}

		/* Order row base */
		.tcbf-order-row {
			display: flex;
			align-items: flex-start;
			gap: 16px;
			background: transparent;
		}
		.tcbf-order-row--parent {
			border-bottom: 1px solid rgba(0, 0, 0, 0.06);
			border-left: 3px solid var(--tcbf-accent);
			padding: 10px 10px 10px 20px;
		}
		.tcbf-order-row--child {
			position: relative;
			border-left: 3px solid color-mix(in srgb, var(--tcbf-accent) 50%, transparent);
			padding: 10px 10px 10px 40px;
		}

		/* Thumbnails */
		.tcbf-order-thumb {
			flex-shrink: 0;
			width: 70px;
			height: 70px;
		}
		.tcbf-order-thumb img {
			width: 70px;
			height: 70px;
			object-fit: cover;
			border-radius: 8px;
			border: 1px solid #e5e7eb;
		}
		.tcbf-order-thumb--placeholder {
			display: block;
			width: 70px;
			height: 70px;
			background: #f3f4f6;
			border-radius: 8px;
			border: 1px solid #e5e7eb;
		}
		.tcbf-order-row--child .tcbf-order-thumb {
			width: 55px;
			height: 55px;
		}
		.tcbf-order-row--child .tcbf-order-thumb img,
		.tcbf-order-row--child .tcbf-order-thumb--placeholder {
			width: 55px;
			height: 55px;
		}

		/* Content area */
		.tcbf-order-content {
			flex: 1;
			min-width: 0;
		}

		/* Title */
		.tcbf-order-title {
			font-weight: 600;
			font-size: 16px;
			margin-bottom: 6px;
			line-height: 1.4;
			color: #111827;
		}
		.tcbf-order-title a {
			color: var(--tcbf-accent);
			text-decoration: none;
		}
		.tcbf-order-title a:hover {
			text-decoration: underline;
		}
		.tcbf-order-row--child .tcbf-order-title {
			font-size: 14px;
			font-weight: 500;
		}

		/* Participant badge line */
		.tcbf-participant-line {
			display: flex;
			align-items: center;
			gap: 8px;
			margin-bottom: 8px;
		}
		.tcbf-participant-badge {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);
			color: #312e81;
			padding: 6px 12px;
			border-radius: 6px;
			font-size: 13px;
			font-weight: 600;
			border: 1px solid rgba(99, 102, 241, 0.2);
		}
		.tcbf-participant-icon {
			font-size: 14px;
		}
		.tcbf-participant-name {
			white-space: nowrap;
		}

		/* Status badge (X/V) */
		.tcbf-status-badge {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			width: 22px;
			height: 22px;
			border-radius: 50%;
			font-size: 12px;
			font-weight: 700;
		}
		.tcbf-status-badge--ok {
			background: #d1fae5;
			color: #059669;
		}
		.tcbf-status-badge--pending {
			background: #fef3c7;
			color: #d97706;
		}
		.tcbf-status-badge--fail {
			background: #fee2e2;
			color: #dc2626;
		}

		/* EB Badge line - matching cart style */
		.tcbf-eb-line {
			margin-bottom: 8px;
		}
		.tcbf-eb-badge {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);
			color: #ffffff;
			padding: 5px 10px;
			border-radius: 4px;
			font-size: 12px;
			font-weight: 600;
			line-height: 1.3;
		}
		.tcbf-eb-icon {
			font-size: 14px;
		}
		.tcbf-eb-sep {
			opacity: 0.7;
		}
		.tcbf-eb-amt {
			font-weight: 700;
		}
		.tcbf-eb-label {
			opacity: 0.9;
		}

		/* Meta lines (Fecha, Evento, Bicicleta) */
		.tcbf-order-meta-lines {
			margin-top: 4px;
		}
		.tcbf-meta-line {
			font-size: 13px;
			color: #4b5563;
			line-height: 1.6;
			display: flex;
			gap: 6px;
		}
		.tcbf-meta-label {
			color: #6b7280;
			text-transform: uppercase;
			font-size: 11px;
			letter-spacing: 0.3px;
			flex-shrink: 0;
		}
		.tcbf-meta-value {
			color: #111827;
			font-weight: 500;
		}
		.tcbf-meta-link {
			color: var(--tcbf-accent);
			text-decoration: none;
		}
		.tcbf-meta-link:hover {
			text-decoration: underline;
		}

		/* Rental "included in pack" badge */
		.tcbf-rental-badge-line {
			margin-bottom: 6px;
		}
		.tcbf-badge-included {
			display: inline-flex;
			align-items: center;
			gap: 4px;
			font-size: 11px;
			font-weight: 500;
			color: rgba(55, 65, 81, 0.8);
			background: rgba(107, 114, 128, 0.12);
			padding: 4px 10px;
			border-radius: 4px;
		}

		/* Talla line - prominent styling */
		.tcbf-talla-line .tcbf-talla-value {
			font-weight: 700;
			color: #111827;
		}

		/* Price column */
		.tcbf-order-price {
			flex-shrink: 0;
			font-weight: 700;
			font-size: 16px;
			text-align: right;
			white-space: nowrap;
			color: #111827;
			min-width: 80px;
		}
		.tcbf-order-row--child .tcbf-order-price {
			font-size: 14px;
			font-weight: 600;
			color: #6b7280;
		}

		/* Standalone row (non-pack items) */
		.tcbf-order-row--standalone {
			background: rgba(255, 255, 255, 0.4);
			border: 1px solid rgba(0, 0, 0, 0.06);
			border-radius: 8px;
			padding: 14px;
			margin-bottom: 12px;
		}

		/* Quantity badge */
		.tcbf-qty {
			font-weight: 500;
			color: #6b7280;
			font-size: 14px;
		}

		/* Pack footer (totals) */
		.tcbf-pack-footer {
			margin-top: 12px;
			padding: 12px 16px;
			background: rgba(0, 0, 0, 0.02);
			border-top: 1px solid rgba(0, 0, 0, 0.06);
			border-radius: 0 0 6px 6px;
		}
		.tcbf-pack-footer-line {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 4px 0;
			font-size: 13px;
		}
		.tcbf-pack-footer-label {
			color: #6b7280;
		}
		.tcbf-pack-footer-value {
			font-weight: 600;
			color: #374151;
		}
		.tcbf-pack-footer-discount {
			color: #059669;
		}
		.tcbf-pack-footer-total {
			border-top: 1px solid rgba(0, 0, 0, 0.08);
			margin-top: 6px;
			padding-top: 8px;
		}
		.tcbf-pack-footer-total .tcbf-pack-footer-label {
			font-weight: 600;
			color: #374151;
		}
		.tcbf-pack-footer-total .tcbf-pack-footer-value {
			font-weight: 700;
			color: #111827;
			font-size: 14px;
		}

		/* Responsive - tablet */
		@media (max-width: 768px) {
			.tcbf-order-thumb {
				width: 60px;
				height: 60px;
			}
			.tcbf-order-thumb img,
			.tcbf-order-thumb--placeholder {
				width: 60px;
				height: 60px;
			}
			.tcbf-order-row--child .tcbf-order-thumb,
			.tcbf-order-row--child .tcbf-order-thumb img,
			.tcbf-order-row--child .tcbf-order-thumb--placeholder {
				width: 50px;
				height: 50px;
			}
			.tcbf-order-title {
				font-size: 15px;
			}
			.tcbf-participant-badge {
				font-size: 12px;
				padding: 5px 10px;
			}
			.tcbf-eb-badge {
				font-size: 11px;
				padding: 4px 8px;
			}
		}

		/* Responsive - mobile */
		@media (max-width: 480px) {
			.tcbf-order-row {
				flex-wrap: wrap;
				gap: 12px;
			}
			.tcbf-order-row--parent {
				padding: 10px 8px 10px 16px;
			}
			.tcbf-order-row--child {
				padding: 10px 8px 10px 28px;
			}
			.tcbf-order-price {
				width: 100%;
				text-align: left;
				margin-top: 4px;
				font-size: 15px;
			}
			.tcbf-participant-badge {
				font-size: 11px;
				padding: 4px 8px;
			}
			.tcbf-meta-line {
				flex-direction: column;
				gap: 2px;
			}
		}
		</style>
		<?php
	}

}
