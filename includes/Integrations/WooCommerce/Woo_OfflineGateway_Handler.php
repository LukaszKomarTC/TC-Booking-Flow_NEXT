<?php
/**
 * WooCommerce Offline Payment Gateway Handler
 *
 * This file MUST only be loaded when WooCommerce is available.
 * It extends WC_Payment_Gateway which requires WooCommerce to be loaded first.
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

// Safety check: Only define class if WooCommerce is available
if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
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
		$this->method_title       = __( 'Offline / Partner Invoice', TC_BF_TEXTDOMAIN );
		$this->method_description = __( 'Allows partners and admins to place orders without immediate payment. Orders are invoiced and settled monthly.', TC_BF_TEXTDOMAIN );

		// Load settings
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Partner Invoice', TC_BF_TEXTDOMAIN ) );
		$this->description = $this->get_option( 'description', __( 'Order will be added to your monthly invoice.', TC_BF_TEXTDOMAIN ) );
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
				'title'   => __( 'Enable/Disable', TC_BF_TEXTDOMAIN ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Offline / Partner Invoice Gateway', TC_BF_TEXTDOMAIN ),
				'default' => 'yes',
			],
			'title' => [
				'title'       => __( 'Title', TC_BF_TEXTDOMAIN ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', TC_BF_TEXTDOMAIN ),
				'default'     => __( 'Partner Invoice', TC_BF_TEXTDOMAIN ),
				'desc_tip'    => true,
			],
			'description' => [
				'title'       => __( 'Description', TC_BF_TEXTDOMAIN ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', TC_BF_TEXTDOMAIN ),
				'default'     => __( 'Order will be added to your monthly invoice.', TC_BF_TEXTDOMAIN ),
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
				'message' => __( 'Order not found.', TC_BF_TEXTDOMAIN ),
			];
		}

		// Store settlement metadata (canonical keys)
		$user_id      = get_current_user_id();
		$channel      = Woo_OfflineGateway::determine_settlement_channel();
		$partner_code = Woo_OfflineGateway::get_current_user_partner_code();

		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_CHANNEL, $channel );
		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_USER_ID, $user_id );
		$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_TIMESTAMP, current_time( 'mysql' ) );

		if ( $partner_code !== '' ) {
			$order->update_meta_data( Woo_OfflineGateway::META_SETTLEMENT_PARTNER_CODE, $partner_code );
		}

		// ---------------------------------------------------------------
		// Legacy _tc_* settlement meta mirrored for backward compatibility.
		// Partner portal, reports, and older snippets may still read these.
		// Planned removal after external readers are fully migrated (target: 1–2 releases).
		// ---------------------------------------------------------------
		$order->update_meta_data( Woo_OfflineGateway::LEGACY_META_CHANNEL, $channel );
		$order->update_meta_data( Woo_OfflineGateway::LEGACY_META_USER_ID, $user_id );
		if ( $partner_code !== '' ) {
			$order->update_meta_data( Woo_OfflineGateway::LEGACY_META_PARTNER_CODE, $partner_code );
		}
		$order->update_meta_data( Woo_OfflineGateway::META_LEGACY_MIRRORED, '1' );

		// Set order status to invoiced (NOT pending, NOT processing)
		// Do NOT call payment_complete() - we don't want to pretend money was received
		// Note: WooCommerce expects status without 'wc-' prefix in set_status()
		$order->set_status( 'invoiced', __( 'Order placed via offline/partner invoice.', TC_BF_TEXTDOMAIN ) );
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
