<?php
/**
 * TCBF Override: Suppress WooCommerce Bookings default order display.
 *
 * This template intentionally outputs nothing.
 * The "Reserva #xxxx Confirmada..." block is replaced by our TCBF Summary block,
 * rendered via woocommerce_order_details_before_order_table hook.
 *
 * Why suppress:
 * - Removes the "white patch" styling that doesn't match site design
 * - Prevents duplicate booking information (we show our own styled summary)
 * - Gives us full control over booking display across all contexts
 *
 * @see TC_BF\Integrations\WooCommerce\Woo_OrderMeta::render_order_summary_block()
 * @since 0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Intentionally empty - TCBF Summary block handles booking display.
return;
