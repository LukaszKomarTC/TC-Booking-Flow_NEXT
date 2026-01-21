<?php
/**
 * WooCommerce/GravityForms Notification Integration
 *
 * Handles custom GravityForms notification events triggered by WooCommerce order statuses.
 *
 * Notification Strategy:
 * - WC___paid: Fired when order enters a paid-equivalent status (processing, completed, invoiced)
 * - WC___settled: Reserved for future use when invoiced orders are later settled
 *
 * TCBF triggers events. GF conditional logic decides who receives emails.
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

	/**
	 * Order meta keys for notification audit trail.
	 */
	const META_PAID_NOTIFS_SENT       = '_tc_gf_paid_notifs_sent';
	const META_PAID_NOTIFS_SENT_AT    = '_tcbf_paid_notifs_sent_at';
	const META_PAID_NOTIFS_TRIGGER    = '_tcbf_paid_notifs_trigger_status';
	const META_SETTLED_NOTIFS_SENT    = '_tc_gf_settled_notifs_sent';
	const META_SETTLED_NOTIFS_SENT_AT = '_tcbf_settled_notifs_sent_at';

	/* =========================================================
	 * GF notifications (parity with legacy snippets)
	 * ========================================================= */

	/**
	 * Register custom GF notification events.
	 *
	 * WC___paid: Triggered for all paid-equivalent statuses (processing, completed, invoiced)
	 * WC___settled: Reserved for future invoice settlement tracking
	 */
	public static function gf_register_notification_events( array $events ) : array {
		$events['WC___paid']    = __( 'WooCommerce order paid (includes invoiced)', TC_BF_TEXTDOMAIN );
		$events['WC___settled'] = __( 'Invoice settled (future use)', TC_BF_TEXTDOMAIN );
		return $events;
	}

	/**
	 * Fire GF notifications when order enters a paid-equivalent status.
	 *
	 * Paid-equivalent statuses: processing, completed, invoiced
	 * (Defined in Woo_StatusPolicy::get_paid_equivalent_statuses())
	 *
	 * Hooks:
	 * - woocommerce_payment_complete (order id)
	 * - woocommerce_order_status_processing (order id, order)
	 * - woocommerce_order_status_completed (order id, order)
	 * - woocommerce_order_status_invoiced (order id, order)
	 *
	 * @param int|mixed      $order_id    Order ID.
	 * @param \WC_Order|null $maybe_order Order object (if available from hook).
	 * @param string         $trigger     Optional trigger status for audit trail.
	 */
	public static function woo_fire_gf_paid_notifications( $order_id, $maybe_order = null, string $trigger = '' ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// Dedupe: avoid duplicate sends (truthy check for robustness)
		if ( get_post_meta( $order_id, self::META_PAID_NOTIFS_SENT, true ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Determine trigger status for audit trail
		if ( $trigger === '' ) {
			$trigger = $order->get_status();
		}

		// Gather GF entry ids from line items (pack parent items have _gf_entry_id)
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );

		// No GF entries found - skip silently (may be non-pack order)
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

				// Fire WC___paid event - GF conditional logic handles recipient decisions
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
			// Mark as sent + audit metadata
			update_post_meta( $order_id, self::META_PAID_NOTIFS_SENT, '1' );
			update_post_meta( $order_id, self::META_PAID_NOTIFS_SENT_AT, current_time( 'mysql' ) );
			update_post_meta( $order_id, self::META_PAID_NOTIFS_TRIGGER, $trigger );

			\TC_BF\Support\Logger::log('gf.notif.wc_paid.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
				'trigger'   => $trigger,
			]);
		}
	}

	/**
	 * Fire GF notifications when an invoice is settled.
	 *
	 * Reserved for future use when invoiced orders are later marked as settled.
	 * Hook: woocommerce_order_status_settled (order id, order)
	 *
	 * @param int|mixed      $order_id    Order ID.
	 * @param \WC_Order|null $maybe_order Order object (if available from hook).
	 */
	public static function woo_fire_gf_settled_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// Dedupe: send once per order
		if ( get_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT, true ) ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Gather GF entry ids from line items
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );
		if ( empty( $entry_ids ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		$did_any = false;
		foreach ( $entry_ids as $eid ) {
			try {
				$entry = \GFAPI::get_entry( $eid );
				if ( is_wp_error($entry) || empty($entry) ) continue;

				$form = \GFAPI::get_form( (int)$entry['form_id'] );
				if ( empty($form) ) continue;

				\GFAPI::send_notifications( $form, $entry, 'WC___settled' );
				$did_any = true;
			} catch ( \Throwable $e ) {
				\TC_BF\Support\Logger::log('gf.notif.wc_settled.exception', [
					'order_id' => $order_id,
					'entry_id' => (int) $eid,
					'err'      => $e->getMessage(),
				], 'error');
			}
		}

		if ( $did_any ) {
			// Mark as sent + audit metadata
			update_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT, '1' );
			update_post_meta( $order_id, self::META_SETTLED_NOTIFS_SENT_AT, current_time( 'mysql' ) );

			\TC_BF\Support\Logger::log('gf.notif.wc_settled.sent', [
				'order_id'  => $order_id,
				'entry_ids' => $entry_ids,
			]);
		}
	}

}
