<?php
/**
 * WooCommerce/GravityForms Notification Integration
 *
 * Handles custom GravityForms notification events triggered by WooCommerce order statuses.
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce Notifications Integration
 *
 * Manages GravityForms notification events for WooCommerce order payment and settlement.
 */
class Woo_Notifications {

	/* =========================================================
	 * GF notifications (parity with legacy snippets)
	 * ========================================================= */

	/**
	 * Register custom GF notification events.
	 * Legacy key: WC___paid
	 */
	public static function gf_register_notification_events( array $events ) : array {
		$events['WC___paid']    = __( 'Woocommerce payment confirmed', 'tc-booking-flow' );
		$events['WC___settled'] = __( 'Reservation confirmed (invoice/offline)', 'tc-booking-flow' );
		return $events;
	}

	/**
	 * Fire GF notifications when Woo payment is confirmed.
	 *
	 * Hooks:
	 * - woocommerce_payment_complete (order id)
	 * - woocommerce_order_status_processing/completed (order id, order)
	 */
	public static function woo_fire_gf_paid_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// Avoid duplicate sends.
		$sent_flag = (string) get_post_meta( $order_id, '_tc_gf_paid_notifs_sent', true );
		if ( $sent_flag === '1' ) return;

		if ( ! class_exists('GFAPI') ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Gather GF entry ids from line items.
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );
		if ( ! $entry_ids ) return;

		$did_any = false;
		foreach ( $entry_ids as $entry_id ) {
			try {
				$entry = \GFAPI::get_entry( (int) $entry_id );
				if ( is_wp_error($entry) || ! is_array($entry) ) continue;
				$form_id = (int) rgar( $entry, 'form_id' );
				if ( $form_id <= 0 ) {
					$form_id = (int) \TC_BF\Admin\Settings::get_form_id();
				}
				if ( $form_id <= 0 ) continue;

				$form = \GFAPI::get_form( $form_id );
				if ( ! is_array($form) || empty($form['id']) ) continue;

				// Send custom notifications.
				\GFAPI::send_notifications( $form, $entry, 'WC___paid' );
				$did_any = true;
			} catch ( \Throwable $e ) {
				\TC_BF\Support\Logger::log('gf.notif.wc_paid.exception', [
					'order_id' => $order_id,
					'entry_id' => (int) $entry_id,
					'err'      => $e->getMessage(),
				], 'error');
			}
		}

		if ( $did_any ) {
			update_post_meta( $order_id, '_tc_gf_paid_notifs_sent', '1' );
			\TC_BF\Support\Logger::log('gf.notif.wc_paid.sent', ['order_id'=>$order_id,'entry_ids'=>$entry_ids]);
		}
	}

	/**
	 * Fire GF notifications when an order is confirmed via invoice/offline settlement.
	 *
	 * Hook: woocommerce_order_status_invoiced (order id, order)
	 */
	public static function woo_fire_gf_settled_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// De-dupe: send once per order.
		if ( get_post_meta( $order_id, '_tc_gf_settled_notifs_sent', true ) ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Gather GF entry ids from line items.
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );
		if ( empty( $entry_ids ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		foreach ( $entry_ids as $eid ) {
			$entry = \GFAPI::get_entry( $eid );
			if ( is_wp_error($entry) || empty($entry) ) continue;

			$form = \GFAPI::get_form( (int)$entry['form_id'] );
			if ( empty($form) ) continue;

			\GFAPI::send_notifications( $form, $entry, 'WC___settled' );
		}

		update_post_meta( $order_id, '_tc_gf_settled_notifs_sent', 1 );
		\TC_BF\Support\Logger::log('gf.settled_notifs.sent', [ 'order_id' => $order_id, 'entry_ids' => $entry_ids ]);
	}

}
