<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Entry State Management — Track GF entry lifecycle with state machine pattern
 *
 * Handles:
 * - State transitions (created → in_cart → paid | removed | expired)
 * - State validation and history logging
 * - Timestamp tracking for all transitions
 * - Order ID association
 * - State-based participant list filtering
 *
 * Key invariant: Once "paid", entries cannot regress to removed/expired
 *
 * Meta keys stored on GF entries:
 * - tcbf_state: Current state (created|in_cart|paid|removed|expired|payment_failed|cancelled|refunded)
 * - tcbf_group_id: Pack group ID (same as entry ID)
 * - tcbf_order_id: WooCommerce order ID when paid
 * - tcbf_in_cart_at: Timestamp when marked in_cart
 * - tcbf_paid_at: Timestamp when paid
 * - tcbf_removed_at: Timestamp when removed
 * - tcbf_expired_at: Timestamp when expired
 * - tcbf_removed_reason: Reason for removal (user_removed|cart_emptied|expired_job|checkout_completed)
 * - tcbf_state_history: Array of state transitions with timestamps
 */
final class Entry_State {

	/**
	 * Meta key constants
	 */
	const META_STATE          = 'tcbf_state';
	const META_GROUP_ID       = 'tcbf_group_id';
	const META_ORDER_ID       = 'tcbf_order_id';
	const META_IN_CART_AT     = 'tcbf_in_cart_at';
	const META_PAID_AT        = 'tcbf_paid_at';
	const META_REMOVED_AT     = 'tcbf_removed_at';
	const META_EXPIRED_AT     = 'tcbf_expired_at';
	const META_CANCELLED_AT   = 'tcbf_cancelled_at';
	const META_REFUNDED_AT    = 'tcbf_refunded_at';
	const META_REMOVED_REASON = 'tcbf_removed_reason';
	const META_STATE_HISTORY  = 'tcbf_state_history';

	/**
	 * State constants
	 */
	const STATE_CREATED        = 'created';        // Entry just created, not yet in cart
	const STATE_IN_CART        = 'in_cart';        // Added to cart successfully
	const STATE_PAID           = 'paid';           // Order created and paid
	const STATE_REMOVED        = 'removed';        // Removed from cart by user
	const STATE_EXPIRED        = 'expired';        // Cart session expired
	const STATE_PAYMENT_FAILED = 'payment_failed'; // Order created but payment failed
	const STATE_CANCELLED      = 'cancelled';      // Order cancelled
	const STATE_REFUNDED       = 'refunded';       // Order refunded

	/**
	 * Removal reasons
	 */
	const REASON_USER_REMOVED      = 'user_removed';      // User clicked remove button
	const REASON_CART_EMPTIED      = 'cart_emptied';      // Cart was emptied
	const REASON_EXPIRED_JOB       = 'expired_job';       // Cron job marked as expired
	const REASON_CHECKOUT_COMPLETE = 'checkout_completed'; // Should not happen (guard against)
	const REASON_ADMIN_ACTION      = 'admin_action';      // Manual admin override

	/**
	 * Valid state transitions
	 *
	 * Key: current state
	 * Value: array of allowed next states
	 */
	const VALID_TRANSITIONS = [
		self::STATE_CREATED => [
			self::STATE_IN_CART,
			self::STATE_EXPIRED, // Edge case: created but never added to cart
		],
		self::STATE_IN_CART => [
			self::STATE_PAID,
			self::STATE_REMOVED,
			self::STATE_EXPIRED,
			self::STATE_PAYMENT_FAILED,
		],
		self::STATE_PAID => [
			self::STATE_CANCELLED,
			self::STATE_REFUNDED,
			// IMPORTANT: cannot go back to removed/expired
		],
		self::STATE_REMOVED => [
			// Terminal state (user can resubmit form to create new entry)
		],
		self::STATE_EXPIRED => [
			// Terminal state
		],
		self::STATE_PAYMENT_FAILED => [
			self::STATE_PAID,     // Retry payment succeeded
			self::STATE_EXPIRED,  // Abandoned after failure
			self::STATE_REMOVED,  // Admin cleanup
		],
		self::STATE_CANCELLED => [
			self::STATE_REFUNDED, // Can refund a cancelled order
			self::STATE_PAID,     // Edge case: cancelled then un-cancelled
		],
		self::STATE_REFUNDED => [
			// Terminal state
		],
	];

	/**
	 * Get current state of an entry
	 *
	 * @param int $entry_id GF entry ID
	 * @return string Current state or empty string if not set
	 */
	public static function get_state( int $entry_id ) : string {
		if ( $entry_id <= 0 ) {
			return '';
		}

		$state = gform_get_meta( $entry_id, self::META_STATE );
		return $state ? (string) $state : '';
	}

	/**
	 * Transition entry to a new state
	 *
	 * Validates the transition, updates meta, logs history, and triggers hooks.
	 *
	 * @param int    $entry_id   GF entry ID
	 * @param string $new_state  Target state
	 * @param string $reason     Reason for transition (optional)
	 * @param int    $order_id   Order ID if applicable
	 * @return bool Success
	 */
	public static function transition_to( int $entry_id, string $new_state, string $reason = '', int $order_id = 0 ) : bool {

		if ( $entry_id <= 0 ) {
			\TC_BF\Support\Logger::log( 'entry_state.transition.invalid_entry', [
				'entry_id' => $entry_id,
			] );
			return false;
		}

		$current_state = self::get_state( $entry_id );

		// If no current state, assume 'created'
		if ( $current_state === '' ) {
			$current_state = self::STATE_CREATED;
		}

		// Validate transition
		if ( ! self::is_valid_transition( $current_state, $new_state ) ) {
			\TC_BF\Support\Logger::log( 'entry_state.transition.invalid', [
				'entry_id'      => $entry_id,
				'current_state' => $current_state,
				'new_state'     => $new_state,
				'reason'        => $reason,
			] );
			return false;
		}

		// Critical guard: Once paid, never regress to removed/expired
		if ( $current_state === self::STATE_PAID && in_array( $new_state, [ self::STATE_REMOVED, self::STATE_EXPIRED ], true ) ) {
			\TC_BF\Support\Logger::log( 'entry_state.transition.guard_paid', [
				'entry_id'      => $entry_id,
				'current_state' => $current_state,
				'new_state'     => $new_state,
				'blocked'       => true,
			] );
			return false;
		}

		// Update state
		gform_update_meta( $entry_id, self::META_STATE, $new_state );

		// Update timestamps based on new state
		$timestamp = current_time( 'timestamp', true );

		switch ( $new_state ) {
			case self::STATE_IN_CART:
				gform_update_meta( $entry_id, self::META_IN_CART_AT, $timestamp );
				// Set group_id to entry_id
				gform_update_meta( $entry_id, self::META_GROUP_ID, $entry_id );
				break;

			case self::STATE_PAID:
				gform_update_meta( $entry_id, self::META_PAID_AT, $timestamp );
				if ( $order_id > 0 ) {
					gform_update_meta( $entry_id, self::META_ORDER_ID, $order_id );
				}
				break;

			case self::STATE_REMOVED:
				gform_update_meta( $entry_id, self::META_REMOVED_AT, $timestamp );
				if ( $reason ) {
					gform_update_meta( $entry_id, self::META_REMOVED_REASON, $reason );
				}
				break;

			case self::STATE_EXPIRED:
				gform_update_meta( $entry_id, self::META_EXPIRED_AT, $timestamp );
				break;

			case self::STATE_CANCELLED:
				gform_update_meta( $entry_id, self::META_CANCELLED_AT, $timestamp );
				break;

			case self::STATE_REFUNDED:
				gform_update_meta( $entry_id, self::META_REFUNDED_AT, $timestamp );
				break;
		}

		// Log state history
		self::add_state_history( $entry_id, $current_state, $new_state, $reason, $order_id );

		\TC_BF\Support\Logger::log( 'entry_state.transition.success', [
			'entry_id'      => $entry_id,
			'from'          => $current_state,
			'to'            => $new_state,
			'reason'        => $reason,
			'order_id'      => $order_id,
		] );

		// Fire action hook for integrations
		do_action( 'tcbf_entry_state_transition', $entry_id, $current_state, $new_state, $reason, $order_id );

		return true;
	}

	/**
	 * Check if a state transition is valid
	 *
	 * @param string $current_state Current state
	 * @param string $new_state     Target state
	 * @return bool Valid transition
	 */
	public static function is_valid_transition( string $current_state, string $new_state ) : bool {

		// If no current state, assume created
		if ( $current_state === '' ) {
			$current_state = self::STATE_CREATED;
		}

		// Check if transition is in allowed list
		if ( ! isset( self::VALID_TRANSITIONS[ $current_state ] ) ) {
			return false;
		}

		return in_array( $new_state, self::VALID_TRANSITIONS[ $current_state ], true );
	}

	/**
	 * Add state history entry
	 *
	 * @param int    $entry_id   GF entry ID
	 * @param string $from_state Previous state
	 * @param string $to_state   New state
	 * @param string $reason     Reason
	 * @param int    $order_id   Order ID if applicable
	 */
	private static function add_state_history( int $entry_id, string $from_state, string $to_state, string $reason, int $order_id ) : void {

		$history = gform_get_meta( $entry_id, self::META_STATE_HISTORY );
		if ( ! is_array( $history ) ) {
			$history = [];
		}

		$history[] = [
			'from'      => $from_state,
			'to'        => $to_state,
			'timestamp' => current_time( 'timestamp', true ),
			'reason'    => $reason,
			'order_id'  => $order_id,
			'user_id'   => get_current_user_id(),
		];

		gform_update_meta( $entry_id, self::META_STATE_HISTORY, $history );
	}

	/**
	 * Mark entry as in_cart
	 *
	 * @param int $entry_id GF entry ID
	 * @return bool Success
	 */
	public static function mark_in_cart( int $entry_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_IN_CART, 'added_to_cart' );
	}

	/**
	 * Mark entry as paid
	 *
	 * @param int $entry_id GF entry ID
	 * @param int $order_id WC Order ID
	 * @return bool Success
	 */
	public static function mark_paid( int $entry_id, int $order_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_PAID, 'order_paid', $order_id );
	}

	/**
	 * Mark entry as removed
	 *
	 * @param int    $entry_id GF entry ID
	 * @param string $reason   Removal reason
	 * @return bool Success
	 */
	public static function mark_removed( int $entry_id, string $reason = self::REASON_USER_REMOVED ) : bool {
		return self::transition_to( $entry_id, self::STATE_REMOVED, $reason );
	}

	/**
	 * Mark entry as expired
	 *
	 * @param int $entry_id GF entry ID
	 * @return bool Success
	 */
	public static function mark_expired( int $entry_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_EXPIRED, self::REASON_EXPIRED_JOB );
	}

	/**
	 * Mark entry as payment_failed
	 *
	 * @param int $entry_id GF entry ID
	 * @param int $order_id WC Order ID
	 * @return bool Success
	 */
	public static function mark_payment_failed( int $entry_id, int $order_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_PAYMENT_FAILED, 'payment_failed', $order_id );
	}

	/**
	 * Mark entry as cancelled
	 *
	 * @param int $entry_id GF entry ID
	 * @param int $order_id WC Order ID
	 * @return bool Success
	 */
	public static function mark_cancelled( int $entry_id, int $order_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_CANCELLED, 'order_cancelled', $order_id );
	}

	/**
	 * Mark entry as refunded
	 *
	 * @param int $entry_id GF entry ID
	 * @param int $order_id WC Order ID
	 * @return bool Success
	 */
	public static function mark_refunded( int $entry_id, int $order_id ) : bool {
		return self::transition_to( $entry_id, self::STATE_REFUNDED, 'order_refunded', $order_id );
	}

	/**
	 * Get all entries with a specific state
	 *
	 * @param string $state      State to filter by
	 * @param int    $form_id    GF form ID
	 * @param int    $page_size  Results per page
	 * @param int    $offset     Offset
	 * @return array Array of entries
	 */
	public static function get_entries_by_state( string $state, int $form_id, int $page_size = 50, int $offset = 0 ) : array {

		if ( ! class_exists( 'GFAPI' ) ) {
			return [];
		}

		$search_criteria = [
			'field_filters' => [
				'mode' => 'all',
				[
					'key'   => self::META_STATE,
					'value' => $state,
				],
			],
		];

		$sorting = [
			'key'        => 'date_created',
			'direction'  => 'DESC',
		];

		$paging = [
			'offset'    => $offset,
			'page_size' => $page_size,
		];

		$entries = \GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

		if ( is_wp_error( $entries ) ) {
			\TC_BF\Support\Logger::log( 'entry_state.query.error', [
				'form_id' => $form_id,
				'state'   => $state,
				'error'   => $entries->get_error_message(),
			] );
			return [];
		}

		return is_array( $entries ) ? $entries : [];
	}

	/**
	 * Get entries eligible for expiry
	 *
	 * Returns entries that are in_cart and older than TTL.
	 *
	 * @param int $form_id GF form ID
	 * @param int $ttl_seconds Time to live in seconds (default: 2 hours)
	 * @param int $page_size Results per page
	 * @param int $offset Offset
	 * @return array Array of entries
	 */
	public static function get_expired_entries( int $form_id, int $ttl_seconds = 7200, int $page_size = 50, int $offset = 0 ) : array {

		// Get all in_cart entries
		$entries = self::get_entries_by_state( self::STATE_IN_CART, $form_id, $page_size, $offset );

		$expired = [];
		$now = current_time( 'timestamp', true );

		foreach ( $entries as $entry ) {
			$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
			if ( $entry_id <= 0 ) {
				continue;
			}

			$in_cart_at = (int) gform_get_meta( $entry_id, self::META_IN_CART_AT );

			// If in_cart_at timestamp is old enough, mark as expired
			if ( $in_cart_at > 0 && ( $now - $in_cart_at ) > $ttl_seconds ) {
				$expired[] = $entry;
			}
		}

		return $expired;
	}

	/**
	 * Get order ID associated with an entry
	 *
	 * @param int $entry_id GF entry ID
	 * @return int Order ID or 0
	 */
	public static function get_order_id( int $entry_id ) : int {
		if ( $entry_id <= 0 ) {
			return 0;
		}

		$order_id = (int) gform_get_meta( $entry_id, self::META_ORDER_ID );
		return $order_id > 0 ? $order_id : 0;
	}

	/**
	 * Check if entry is paid
	 *
	 * @param int $entry_id GF entry ID
	 * @return bool Is paid
	 */
	public static function is_paid( int $entry_id ) : bool {
		return self::get_state( $entry_id ) === self::STATE_PAID;
	}

	/**
	 * Check if entry is in cart
	 *
	 * @param int $entry_id GF entry ID
	 * @return bool Is in cart
	 */
	public static function is_in_cart( int $entry_id ) : bool {
		return self::get_state( $entry_id ) === self::STATE_IN_CART;
	}

	/**
	 * Get state history for an entry
	 *
	 * @param int $entry_id GF entry ID
	 * @return array State history
	 */
	public static function get_state_history( int $entry_id ) : array {
		if ( $entry_id <= 0 ) {
			return [];
		}

		$history = gform_get_meta( $entry_id, self::META_STATE_HISTORY );
		return is_array( $history ) ? $history : [];
	}
}
