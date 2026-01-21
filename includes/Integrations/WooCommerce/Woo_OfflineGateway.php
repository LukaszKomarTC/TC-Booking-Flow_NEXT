<?php
/**
 * WooCommerce Offline Payment Gateway
 *
 * Payment gateway for partners and admins to place orders without immediate payment.
 * Orders are marked as "invoiced" and settled later via monthly billing.
 *
 * Key behaviors:
 * - Only available to logged-in users with specific roles (admin, shop_manager, hotel)
 * - Sets order status to "invoiced" (NOT pending)
 * - Confirms WC Bookings immediately
 * - Stores settlement metadata for audit trail
 * - Does NOT call payment_complete() (no pretend payment)
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * Initialize the Offline Gateway.
 *
 * Must be called after WooCommerce is loaded.
 */
class Woo_OfflineGateway {

	/**
	 * Gateway ID.
	 */
	const GATEWAY_ID = 'tcbf_offline';

	/**
	 * Allowed user roles for this gateway.
	 */
	const ALLOWED_ROLES = [ 'administrator', 'shop_manager', 'hotel' ];

	/**
	 * Order meta keys for settlement tracking.
	 */
	const META_SETTLEMENT_CHANNEL = '_tcbf_settlement_channel';
	const META_SETTLEMENT_USER_ID = '_tcbf_settlement_user_id';
	const META_SETTLEMENT_PARTNER_CODE = '_tcbf_settlement_partner_code';
	const META_SETTLEMENT_TIMESTAMP = '_tcbf_settlement_timestamp';

	/**
	 * Legacy meta keys (for backward compatibility shim).
	 *
	 * @deprecated Planned removal after external readers are fully migrated (target: 1–2 releases).
	 */
	const LEGACY_META_CHANNEL = '_tc_settlement_channel';
	const LEGACY_META_USER_ID = '_tc_settlement_user_id';
	const LEGACY_META_PARTNER_CODE = '_tc_partner_code';
	const META_LEGACY_MIRRORED = '_tcbf_settlement_legacy_mirrored';

	/**
	 * Initialize gateway registration.
	 */
	public static function init() : void {
		// Register gateway class with WooCommerce
		add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_gateway' ] );

		// Note: Booking confirmation happens in process_payment() - no thankyou hook needed
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $gateways Existing gateways.
	 * @return array Modified gateways.
	 */
	public static function register_gateway( array $gateways ) : array {
		// Load the Handler class only when WooCommerce is available
		// This prevents fatal errors if WooCommerce is deactivated
		if ( ! class_exists( __NAMESPACE__ . '\\Woo_OfflineGateway_Handler' ) ) {
			require_once __DIR__ . '/Woo_OfflineGateway_Handler.php';
		}

		if ( class_exists( __NAMESPACE__ . '\\Woo_OfflineGateway_Handler' ) ) {
			$gateways[] = Woo_OfflineGateway_Handler::class;
		}

		return $gateways;
	}

	/**
	 * Confirm all WC Bookings attached to an order.
	 *
	 * @param \WC_Order $order Order object.
	 */
	public static function confirm_order_bookings( \WC_Order $order ) : void {
		// Use WC Bookings native confirmation if available
		if ( class_exists( 'WC_Bookings_Order_Manager' ) && method_exists( 'WC_Bookings_Order_Manager', 'confirm_all_bookings' ) ) {
			\WC_Bookings_Order_Manager::confirm_all_bookings( $order->get_id() );
			\TC_BF\Support\Logger::log( 'offline_gateway.bookings_confirmed', [
				'order_id' => $order->get_id(),
				'method'   => 'WC_Bookings_Order_Manager',
			] );
			return;
		}

		// Fallback: iterate booking items and confirm manually
		foreach ( $order->get_items() as $item ) {
			$booking_id = $item->get_meta( '_booking_id' );
			if ( $booking_id && function_exists( 'get_wc_booking' ) ) {
				$booking = get_wc_booking( $booking_id );
				if ( $booking && $booking->get_status() !== 'confirmed' ) {
					$booking->update_status( 'confirmed' );
				}
			}
		}
	}

	/**
	 * Check if current user can use offline gateway.
	 *
	 * @return bool True if user has allowed role.
	 */
	public static function current_user_can_use() : bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		$user_roles = (array) $user->roles;
		$allowed    = array_intersect( $user_roles, self::ALLOWED_ROLES );

		return ! empty( $allowed );
	}

	/**
	 * Determine settlement channel based on user role.
	 *
	 * @return string Settlement channel identifier.
	 */
	public static function determine_settlement_channel() : string {
		if ( ! is_user_logged_in() ) {
			return 'unknown';
		}

		$user       = wp_get_current_user();
		$user_roles = (array) $user->roles;

		if ( in_array( 'hotel', $user_roles, true ) ) {
			return 'partner_offline';
		}

		if ( in_array( 'administrator', $user_roles, true ) || in_array( 'shop_manager', $user_roles, true ) ) {
			return 'admin_manual';
		}

		return 'unknown';
	}

	/**
	 * Get partner code for current user (if applicable).
	 *
	 * @return string Partner code or empty string.
	 */
	public static function get_current_user_partner_code() : string {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id = get_current_user_id();
		$code    = get_user_meta( $user_id, 'discount__code', true );

		return is_string( $code ) ? trim( $code ) : '';
	}
}
