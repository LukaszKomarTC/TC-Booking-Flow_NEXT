<?php
/**
 * WooCommerce Custom Order Statuses
 *
 * Registers custom order statuses used by TC Booking Flow:
 * - wc-invoiced: Partner/admin offline orders (paid-equivalent)
 * - wc-settled: Future use for invoice settlement tracking
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Integrations\WooCommerce;

if ( ! defined('ABSPATH') ) exit;

/**
 * WooCommerce Order Status Registration
 */
class Woo_OrderStatus {

	/**
	 * Initialize order status registration.
	 */
	public static function init() : void {
		// Register custom post statuses
		add_action( 'init', [ __CLASS__, 'register_order_statuses' ], 5 );

		// Add to WooCommerce order status list
		add_filter( 'wc_order_statuses', [ __CLASS__, 'add_order_statuses' ], 10, 1 );

		// Add bulk action for marking orders as invoiced
		add_filter( 'bulk_actions-edit-shop_order', [ __CLASS__, 'register_bulk_actions' ], 20, 1 );
		add_filter( 'handle_bulk_actions-edit-shop_order', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'bulk_action_admin_notice' ] );

		// HPOS compatibility: bulk actions for woocommerce_page_wc-orders screen
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'register_bulk_actions' ], 20, 1 );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', [ __CLASS__, 'handle_bulk_actions' ], 10, 3 );
	}

	/**
	 * Register custom order statuses as WordPress post statuses.
	 */
	public static function register_order_statuses() : void {
		// Invoiced status (paid-equivalent for partners/admins)
		register_post_status( 'wc-invoiced', [
			'label'                     => _x( 'Invoiced', 'Order status', 'tc-booking-flow' ),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Invoiced <span class="count">(%s)</span>',
				'Invoiced <span class="count">(%s)</span>',
				'tc-booking-flow'
			),
		] );

		// Settled status (stub for future invoice settlement tracking)
		register_post_status( 'wc-settled', [
			'label'                     => _x( 'Settled', 'Order status', 'tc-booking-flow' ),
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			/* translators: %s: number of orders */
			'label_count'               => _n_noop(
				'Settled <span class="count">(%s)</span>',
				'Settled <span class="count">(%s)</span>',
				'tc-booking-flow'
			),
		] );
	}

	/**
	 * Add custom statuses to WooCommerce order status dropdown.
	 *
	 * @param array $statuses Existing order statuses.
	 * @return array Modified order statuses.
	 */
	public static function add_order_statuses( array $statuses ) : array {
		// Insert after 'wc-on-hold' for logical ordering
		$new_statuses = [];
		foreach ( $statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;
			if ( $key === 'wc-on-hold' ) {
				$new_statuses['wc-invoiced'] = _x( 'Invoiced', 'Order status', 'tc-booking-flow' );
				$new_statuses['wc-settled']  = _x( 'Settled', 'Order status', 'tc-booking-flow' );
			}
		}

		// Fallback if wc-on-hold wasn't found
		if ( ! isset( $new_statuses['wc-invoiced'] ) ) {
			$new_statuses['wc-invoiced'] = _x( 'Invoiced', 'Order status', 'tc-booking-flow' );
			$new_statuses['wc-settled']  = _x( 'Settled', 'Order status', 'tc-booking-flow' );
		}

		return $new_statuses;
	}

	/**
	 * Register bulk actions for order status changes.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function register_bulk_actions( array $actions ) : array {
		$actions['mark_invoiced'] = __( 'Change status to invoiced', 'tc-booking-flow' );
		$actions['mark_settled']  = __( 'Change status to settled', 'tc-booking-flow' );
		return $actions;
	}

	/**
	 * Handle bulk action for marking orders with custom statuses.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Action being performed.
	 * @param array  $post_ids    Order IDs being processed.
	 * @return string Modified redirect URL.
	 */
	public static function handle_bulk_actions( string $redirect_to, string $action, array $post_ids ) : string {
		$status_map = [
			'mark_invoiced' => 'wc-invoiced',
			'mark_settled'  => 'wc-settled',
		];

		if ( ! isset( $status_map[ $action ] ) ) {
			return $redirect_to;
		}

		$new_status = $status_map[ $action ];
		$changed    = 0;

		foreach ( $post_ids as $post_id ) {
			$order = wc_get_order( $post_id );
			if ( ! $order ) {
				continue;
			}

			$order->update_status( $new_status, __( 'Order status changed via bulk action.', 'tc-booking-flow' ), true );
			$changed++;
		}

		return add_query_arg( [
			'tcbf_bulk_status_changed' => $changed,
			'tcbf_bulk_status_to'      => $new_status,
		], $redirect_to );
	}

	/**
	 * Display admin notice after bulk status change.
	 */
	public static function bulk_action_admin_notice() : void {
		if ( empty( $_REQUEST['tcbf_bulk_status_changed'] ) ) {
			return;
		}

		$changed = (int) $_REQUEST['tcbf_bulk_status_changed'];
		$status  = isset( $_REQUEST['tcbf_bulk_status_to'] ) ? sanitize_text_field( $_REQUEST['tcbf_bulk_status_to'] ) : '';

		$status_labels = [
			'wc-invoiced' => __( 'Invoiced', 'tc-booking-flow' ),
			'wc-settled'  => __( 'Settled', 'tc-booking-flow' ),
		];

		$label = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			/* translators: 1: number of orders, 2: status label */
			sprintf(
				_n(
					'%1$d order status changed to %2$s.',
					'%1$d order statuses changed to %2$s.',
					$changed,
					'tc-booking-flow'
				),
				$changed,
				'<strong>' . esc_html( $label ) . '</strong>'
			)
		);
	}
}
