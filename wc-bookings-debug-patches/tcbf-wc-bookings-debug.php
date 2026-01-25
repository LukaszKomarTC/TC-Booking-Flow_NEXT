<?php
/**
 * TCBF WC Bookings Debug Logger
 *
 * Drop this file into wp-content/mu-plugins/ to enable debug logging.
 * Remove it when done debugging.
 *
 * Logs will appear in wp-content/debug.log (ensure WP_DEBUG_LOG is enabled)
 * or in PHP error log.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Only run if WC Bookings is active
add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Bookings' ) ) {
        return;
    }

    // Hook into checkout validation to log cart item booking data
    add_action( 'woocommerce_after_checkout_validation', 'tcbf_debug_checkout_validation', 5, 2 );

    // Hook into add_to_cart to log booking data when items are added
    add_action( 'woocommerce_add_to_cart', 'tcbf_debug_add_to_cart', 5, 6 );

    // Log when cart is loaded from session
    add_action( 'woocommerce_cart_loaded_from_session', 'tcbf_debug_cart_from_session', 5 );
}, 20 );

/**
 * Debug: Log cart booking data during checkout validation
 */
function tcbf_debug_checkout_validation( $data, $errors ) {
    if ( ! WC()->cart ) {
        return;
    }

    error_log( '========================================' );
    error_log( 'TCBF_DEBUG: CHECKOUT VALIDATION START' );
    error_log( '========================================' );

    $cart_items = WC()->cart->get_cart();
    $item_index = 0;

    foreach ( $cart_items as $cart_key => $cart_item ) {
        $item_index++;
        $product = $cart_item['data'];

        // Skip non-booking products
        if ( ! function_exists( 'is_wc_booking_product' ) || ! is_wc_booking_product( $product ) ) {
            continue;
        }

        $booking_data = $cart_item['booking'] ?? [];

        error_log( sprintf(
            'TCBF_DEBUG [%d] === CART ITEM: %s ===',
            $item_index,
            $cart_key
        ));

        error_log( sprintf(
            'TCBF_DEBUG [%d] Product: ID=%d, Name=%s',
            $item_index,
            $product->get_id(),
            $product->get_name()
        ));

        // Log critical booking fields
        $critical_fields = [
            '_booking_id',
            '_start_date',
            '_end_date',
            '_tc_scope',
            '_resource_id',
            '_all_day',
        ];

        foreach ( $critical_fields as $field ) {
            $value = $booking_data[ $field ] ?? 'NOT_SET';
            $type = isset( $booking_data[ $field ] ) ? gettype( $booking_data[ $field ] ) : 'n/a';

            error_log( sprintf(
                'TCBF_DEBUG [%d] %s = %s (type: %s)',
                $item_index,
                $field,
                var_export( $value, true ),
                $type
            ));
        }

        // If _booking_id exists, try to load and inspect the WC_Booking
        if ( isset( $booking_data['_booking_id'] ) && $booking_data['_booking_id'] ) {
            $booking_id = $booking_data['_booking_id'];

            try {
                $booking = new WC_Booking( $booking_id );

                error_log( sprintf(
                    'TCBF_DEBUG [%d] WC_Booking loaded: ID=%d, start=%s (type=%s), end=%s, status=%s',
                    $item_index,
                    $booking->get_id(),
                    var_export( $booking->get_start(), true ),
                    gettype( $booking->get_start() ),
                    var_export( $booking->get_end(), true ),
                    $booking->get_status()
                ));

                // Check for problematic start value
                $start = $booking->get_start();
                if ( $start === '' || $start === null || $start === 0 || $start === false ) {
                    error_log( sprintf(
                        'TCBF_DEBUG [%d] *** WARNING: BOOKING HAS INVALID START VALUE! ***',
                        $item_index
                    ));
                    error_log( sprintf(
                        'TCBF_DEBUG [%d] This will cause "Unsupported operand types: string - int" crash!',
                        $item_index
                    ));
                }
            } catch ( Exception $e ) {
                error_log( sprintf(
                    'TCBF_DEBUG [%d] ERROR loading WC_Booking(%s): %s',
                    $item_index,
                    var_export( $booking_id, true ),
                    $e->getMessage()
                ));
            }
        } else {
            error_log( sprintf(
                'TCBF_DEBUG [%d] *** WARNING: NO _booking_id SET! ***',
                $item_index
            ));
        }

        // Log all booking array keys for reference
        error_log( sprintf(
            'TCBF_DEBUG [%d] All booking keys: %s',
            $item_index,
            implode( ', ', array_keys( $booking_data ) )
        ));

        error_log( '---' );
    }

    error_log( '========================================' );
    error_log( 'TCBF_DEBUG: CHECKOUT VALIDATION END' );
    error_log( '========================================' );
}

/**
 * Debug: Log when items are added to cart
 */
function tcbf_debug_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
    if ( ! isset( $cart_item_data['booking'] ) ) {
        return;
    }

    error_log( '========================================' );
    error_log( 'TCBF_DEBUG: ADD TO CART' );
    error_log( '========================================' );

    error_log( sprintf(
        'TCBF_DEBUG add_to_cart: cart_key=%s, product_id=%d',
        $cart_item_key,
        $product_id
    ));

    $booking_data = $cart_item_data['booking'];

    $critical_fields = [
        '_booking_id',
        '_start_date',
        '_end_date',
        '_tc_scope',
        '_resource_id',
    ];

    foreach ( $critical_fields as $field ) {
        $value = $booking_data[ $field ] ?? 'NOT_SET';
        $type = isset( $booking_data[ $field ] ) ? gettype( $booking_data[ $field ] ) : 'n/a';

        error_log( sprintf(
            'TCBF_DEBUG add_to_cart: %s = %s (type: %s)',
            $field,
            var_export( $value, true ),
            $type
        ));
    }

    // If _booking_id was set, verify it in database
    if ( isset( $booking_data['_booking_id'] ) && $booking_data['_booking_id'] ) {
        global $wpdb;
        $booking_id = $booking_data['_booking_id'];

        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'wc_booking'",
            $booking_id
        ));

        if ( $exists ) {
            // Check the booking's meta
            $start_meta = get_post_meta( $booking_id, '_booking_start', true );
            $end_meta = get_post_meta( $booking_id, '_booking_end', true );
            $status = get_post_status( $booking_id );

            error_log( sprintf(
                'TCBF_DEBUG add_to_cart: DB booking exists! _booking_start=%s, _booking_end=%s, status=%s',
                var_export( $start_meta, true ),
                var_export( $end_meta, true ),
                $status
            ));
        } else {
            error_log( sprintf(
                'TCBF_DEBUG add_to_cart: *** WARNING: _booking_id=%d does NOT exist in database! ***',
                $booking_id
            ));
        }
    }

    error_log( '========================================' );
}

/**
 * Debug: Log cart loaded from session
 */
function tcbf_debug_cart_from_session( $cart ) {
    error_log( '========================================' );
    error_log( 'TCBF_DEBUG: CART LOADED FROM SESSION' );
    error_log( '========================================' );

    $cart_items = $cart->get_cart();
    $booking_items = 0;

    foreach ( $cart_items as $cart_key => $cart_item ) {
        if ( ! isset( $cart_item['booking'] ) ) {
            continue;
        }

        $booking_items++;
        $booking_data = $cart_item['booking'];

        error_log( sprintf(
            'TCBF_DEBUG session: cart_key=%s, _booking_id=%s, _tc_scope=%s',
            $cart_key,
            var_export( $booking_data['_booking_id'] ?? 'NOT_SET', true ),
            $booking_data['_tc_scope'] ?? 'unknown'
        ));

        // Verify booking still exists in DB
        if ( isset( $booking_data['_booking_id'] ) && $booking_data['_booking_id'] ) {
            $booking_id = $booking_data['_booking_id'];
            $booking = get_post( $booking_id );

            if ( ! $booking || $booking->post_type !== 'wc_booking' ) {
                error_log( sprintf(
                    'TCBF_DEBUG session: *** WARNING: _booking_id=%d no longer exists or is not wc_booking! ***',
                    $booking_id
                ));
            } elseif ( $booking->post_status !== 'in-cart' ) {
                error_log( sprintf(
                    'TCBF_DEBUG session: *** NOTE: _booking_id=%d status is "%s" (expected "in-cart") ***',
                    $booking_id,
                    $booking->post_status
                ));
            }
        }
    }

    error_log( sprintf(
        'TCBF_DEBUG session: Total booking items in cart: %d',
        $booking_items
    ));
    error_log( '========================================' );
}
