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
}
