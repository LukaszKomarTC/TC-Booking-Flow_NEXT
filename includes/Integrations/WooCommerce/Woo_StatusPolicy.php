<?php
/**
 * WooCommerce Order Status Policy
 *
 * Centralized policy for order status classification.
 * This is the single source of truth for determining which statuses
 * are considered "paid-equivalent" for notifications and confirmations.
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * Order Status Policy
 *
 * Defines canonical status classifications used throughout TCBF.
 * All status checks should reference this class to avoid ad-hoc duplication.
 */
class Woo_StatusPolicy {

	/**
	 * Paid-equivalent statuses.
	 *
	 * Orders in these statuses are treated as "paid" for:
	 * - GF notifications (WC___paid event)
	 * - Booking confirmations
	 * - Entry state updates
	 * - Partner portal visibility
	 *
	 * @var array<string>
	 */
	private static array $paid_equivalent = [
		'processing',
		'completed',
		'invoiced',
	];

	/**
	 * Check if a status is paid-equivalent.
	 *
	 * @param string $status Order status (with or without 'wc-' prefix).
	 * @return bool True if paid-equivalent.
	 */
	public static function is_paid_equivalent( string $status ) : bool {
		// Normalize: remove 'wc-' prefix if present
		$status = self::normalize_status( $status );
		return in_array( $status, self::$paid_equivalent, true );
	}

	/**
	 * Get all paid-equivalent statuses.
	 *
	 * @param bool $with_prefix Whether to include 'wc-' prefix.
	 * @return array<string> List of paid-equivalent statuses.
	 */
	public static function get_paid_equivalent_statuses( bool $with_prefix = false ) : array {
		if ( ! $with_prefix ) {
			return self::$paid_equivalent;
		}

		return array_map( function( $status ) {
			return 'wc-' . $status;
		}, self::$paid_equivalent );
	}

	/**
	 * Check if an order is in a paid-equivalent status.
	 *
	 * @param \WC_Order|int $order Order object or ID.
	 * @return bool True if order is paid-equivalent.
	 */
	public static function order_is_paid_equivalent( $order ) : bool {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( (int) $order );
		}

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		return self::is_paid_equivalent( $order->get_status() );
	}

	/**
	 * Normalize status by removing 'wc-' prefix.
	 *
	 * @param string $status Status string.
	 * @return string Normalized status without prefix.
	 */
	public static function normalize_status( string $status ) : string {
		return str_starts_with( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Get status with 'wc-' prefix.
	 *
	 * @param string $status Status string.
	 * @return string Status with prefix.
	 */
	public static function prefix_status( string $status ) : string {
		$status = self::normalize_status( $status );
		return 'wc-' . $status;
	}

	/**
	 * Check if status transition is to a paid-equivalent status.
	 *
	 * Useful for hooks that fire on status transitions.
	 *
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return bool True if transitioning TO a paid-equivalent status.
	 */
	public static function is_transition_to_paid( string $old_status, string $new_status ) : bool {
		return self::is_paid_equivalent( $new_status );
	}

	/**
	 * Check if this is a first-time transition to paid-equivalent.
	 *
	 * Returns true only if:
	 * - New status is paid-equivalent
	 * - Old status was NOT paid-equivalent
	 *
	 * @param string $old_status Previous status.
	 * @param string $new_status New status.
	 * @return bool True if first transition to paid.
	 */
	public static function is_first_paid_transition( string $old_status, string $new_status ) : bool {
		$old_paid = self::is_paid_equivalent( $old_status );
		$new_paid = self::is_paid_equivalent( $new_status );

		return $new_paid && ! $old_paid;
	}

	/**
	 * Settlement statuses (future use).
	 *
	 * Orders in these statuses indicate invoice has been settled/paid.
	 *
	 * @var array<string>
	 */
	private static array $settled_statuses = [
		'settled',
	];

	/**
	 * Check if a status indicates settlement.
	 *
	 * @param string $status Order status.
	 * @return bool True if settled status.
	 */
	public static function is_settled( string $status ) : bool {
		$status = self::normalize_status( $status );
		return in_array( $status, self::$settled_statuses, true );
	}

	/**
	 * Offline/invoice statuses.
	 *
	 * Orders in these statuses were placed without immediate payment.
	 *
	 * @var array<string>
	 */
	private static array $offline_statuses = [
		'invoiced',
	];

	/**
	 * Check if a status indicates offline/invoice order.
	 *
	 * @param string $status Order status.
	 * @return bool True if offline status.
	 */
	public static function is_offline( string $status ) : bool {
		$status = self::normalize_status( $status );
		return in_array( $status, self::$offline_statuses, true );
	}
}
