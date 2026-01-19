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
}
