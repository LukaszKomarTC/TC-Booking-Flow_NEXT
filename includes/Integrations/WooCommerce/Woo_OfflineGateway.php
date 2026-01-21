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
	 * Initialize gateway registration.
	 */
	public static function init() : void {
		// Register gateway class with WooCommerce
		add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_gateway' ] );

		// Process order after checkout (confirm bookings, set metadata)
		add_action( 'woocommerce_thankyou_' . self::GATEWAY_ID, [ __CLASS__, 'process_order_confirmation' ], 10, 1 );
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array $gateways Existing gateways.
	 * @return array Modified gateways.
	 */
	public static function register_gateway( array $gateways ) : array {
		$gateways[] = Woo_OfflineGateway_Handler::class;
		return $gateways;
	}

	/**
	 * Process order confirmation after checkout.
	 *
	 * - Confirms WC Bookings
	 * - Sets settlement metadata
	 *
	 * @param int $order_id Order ID.
	 */
	public static function process_order_confirmation( int $order_id ) : void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Only process for our gateway
		if ( $order->get_payment_method() !== self::GATEWAY_ID ) {
			return;
		}

		// Confirm bookings immediately
		self::confirm_order_bookings( $order );

		\TC_BF\Support\Logger::log( 'offline_gateway.order_confirmed', [
			'order_id' => $order_id,
			'status'   => $order->get_status(),
		] );
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

/**
 * WooCommerce Payment Gateway Handler
 *
 * Extends WC_Payment_Gateway to provide the actual gateway implementation.
 */
class Woo_OfflineGateway_Handler extends \WC_Payment_Gateway {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = Woo_OfflineGateway::GATEWAY_ID;
		$this->icon               = '';
		$this->has_fields         = false;
		$this->method_title       = __( 'Offline / Partner Invoice', 'tc-booking-flow' );
		$this->method_description = __( 'Allows partners and admins to place orders without immediate payment. Orders are invoiced and settled monthly.', 'tc-booking-flow' );

		// Load settings
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Partner Invoice', 'tc-booking-flow' ) );
		$this->description = $this->get_option( 'description', __( 'Order will be added to your monthly invoice.', 'tc-booking-flow' ) );
		$this->enabled     = $this->get_option( 'enabled', 'yes' );

		// Save settings
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	/**
	 * Initialize gateway settings form fields.
	 */
	public function init_form_fields() : void {
		$this->form_fields = [
			'enabled' => [
				'title'   => __( 'Enable/Disable', 'tc-booking-flow' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Offline / Partner Invoice Gateway', 'tc-booking-flow' ),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __( 'Title', 'tc-booking-flow' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'tc-booking-flow' ),
				'default'     => __( 'Partner Invoice', 'tc-booking-flow' ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', 'tc-booking-flow' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'tc-booking-flow' ),
				'default'     => __( 'Order will be added to your monthly invoice.', 'tc-booking-flow' ),
				'desc_tip'    => true,
			],
		];
	}

	/**
	 * Check if gateway is available.
	 *
	 * Only available to users with allowed roles.
	 *
	 * @return bool True if available.
	 */
	public function is_available() : bool {
		if ( ! parent::is_available() ) {
			return false;
		}

		return Woo_OfflineGateway::current_user_can_use();
	}

	/**
	 * Process the payment.
	 *
	 * @param int $order_id Order ID.
	 * @return array Result array.
	 */
	public function process_payment( $order_id ) : array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return [
				'result'  => 'failure',
				'message' => __( 'Order not found.', 'tc-booking-flow' ),
			];
		}

		// Store settlement metadata
		$user_id      = get_current_user_id();
		$channel      = Woo_OfflineGateway::determine_settlement_channel();
		$partner_code = Woo_OfflineGateway::get_current_user_partner_code();

		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_CHANNEL, $channel );
		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_USER_ID, $user_id );
		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_TIMESTAMP, current_time( 'mysql' ) );

		if ( $partner_code !== '' ) {
			$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_PARTNER_CODE, $partner_code );
		}

		// Set order status to invoiced (NOT pending, NOT processing)
		// Do NOT call payment_complete() - we don't want to pretend money was received
		$order->set_status( 'wc-invoiced', __( 'Order placed via offline/partner invoice.', 'tc-booking-flow' ) );
		$order->save();

		// Confirm bookings immediately
		Woo_OfflineGateway::confirm_order_bookings( $order );

		// Log the transaction
		\TC_BF\Support\Logger::log( 'offline_gateway.payment_processed', [
			'order_id'     => $order_id,
			'user_id'      => $user_id,
			'channel'      => $channel,
			'partner_code' => $partner_code,
		] );

		// Empty cart
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		// Return success with redirect to thank you page
		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}
}
