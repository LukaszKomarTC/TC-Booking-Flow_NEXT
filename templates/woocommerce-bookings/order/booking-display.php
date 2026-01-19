<?php
/**
 * Booking display in order (TCBF override - intentionally empty)
 *
 * This template suppresses the default WooCommerce Bookings "booking display"
 * output in order views. TCBF handles booking display via the grouped renderer
 * in Woo_OrderMeta::render_grouped_order_items_table().
 *
 * @see \TC_BF\Integrations\WooCommerce\Woo_OrderMeta::render_grouped_order_items_table()
 * @package TC_Booking_Flow
 */

defined( 'ABSPATH' ) || exit;

// Intentionally empty - TCBF grouped renderer handles booking display.
