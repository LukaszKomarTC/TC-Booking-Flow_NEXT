<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner portal (My Account endpoint) – legacy parity for snippet-based reporting.
 *
 * Endpoint: /my-account/partner/
 *
 * Displays orders linked to the current partner via stored order meta:
 * - partner_id
 * - partner_code
 * - partner_base_total
 * - client_total
 * - partner_commission
 */
final class Partner_Portal {

    const ENDPOINT = 'partner';

    public static function init() : void {
        // Register endpoint
        add_action('init', [ __CLASS__, 'add_endpoint' ]);

        // Add menu item
        add_filter('woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 40, 1);

        // Render endpoint content
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [ __CLASS__, 'render_endpoint' ]);
    }

    public static function add_endpoint() : void {
        if ( function_exists('add_rewrite_endpoint') ) {
            add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
        }
    }

    /**
     * Who can see the partner portal.
     *
     * Legacy parity: ONLY
     * - administrator
     * - hotel
     */
    private static function current_user_can_view() : bool {
        if ( ! is_user_logged_in() ) return false;

        if ( current_user_can('manage_options') ) return true; // administrators

        $u = wp_get_current_user();
        if ( ! $u || empty($u->roles) ) return false;

        return in_array('hotel', (array) $u->roles, true );
    }

    public static function add_menu_item( array $items ) : array {
        if ( ! self::current_user_can_view() ) return $items;

        // Insert after "orders" if present; otherwise append.
        $new = [];
        $inserted = false;
        foreach ( $items as $key => $label ) {
            $new[$key] = $label;
            if ( $key === 'orders' ) {
                $new[self::ENDPOINT] = __('Partner report', TC_BF_TEXTDOMAIN);
                $inserted = true;
            }
        }
        if ( ! $inserted ) {
            $new[self::ENDPOINT] = __('Partner report', TC_BF_TEXTDOMAIN);
        }
        return $new;
    }

    public static function render_endpoint() : void {
        if ( ! self::current_user_can_view() ) {
            echo '<p>' . esc_html__('You do not have access to this page.', TC_BF_TEXTDOMAIN) . '</p>';
            return;
        }

        $uid = get_current_user_id();
        $partner_code = trim((string) get_user_meta( $uid, 'discount__code', true ));
        $partner_pct  = (float) get_user_meta( $uid, 'usrdiscount', true );
        $partner_code_norm = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($partner_code) : strtolower($partner_code);

        if ( $partner_code_norm === '' ) {
            echo '<p>' . esc_html__('No partner code linked to your user.', TC_BF_TEXTDOMAIN) . '</p>';
            return;
        }

        // Filters
        $date_from = isset($_GET['tc_from']) ? sanitize_text_field((string) wp_unslash($_GET['tc_from'])) : '';
        $date_to   = isset($_GET['tc_to']) ? sanitize_text_field((string) wp_unslash($_GET['tc_to'])) : '';
        $status    = isset($_GET['tc_status']) ? sanitize_text_field((string) wp_unslash($_GET['tc_status'])) : '';

        // Pagination
        $paged = isset($_GET['tc_paged']) ? max(1, (int) $_GET['tc_paged']) : 1;
        $per_page = 30;

        echo '<h3>' . esc_html__('Partner report', TC_BF_TEXTDOMAIN) . '</h3>';
        echo '<p style="margin:0 0 12px;"><strong>' . esc_html__('Partner code:', TC_BF_TEXTDOMAIN) . '</strong> ' . esc_html($partner_code_norm) . ' &nbsp; <strong>' . esc_html__('Commission:', TC_BF_TEXTDOMAIN) . '</strong> ' . esc_html(number_format_i18n($partner_pct, 2)) . '%</p>';

        // Simple filter form
        echo '<form method="get" action="' . esc_url( wc_get_account_endpoint_url(self::ENDPOINT) ) . '" style="margin: 0 0 16px;">';
        echo '<label style="margin-right:12px;">' . esc_html__('From', TC_BF_TEXTDOMAIN) . ' <input type="date" name="tc_from" value="' . esc_attr($date_from) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__('To', TC_BF_TEXTDOMAIN) . ' <input type="date" name="tc_to" value="' . esc_attr($date_to) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__('Status', TC_BF_TEXTDOMAIN) . ' <select name="tc_status">';
        $opts = [
            '' => __('Any', TC_BF_TEXTDOMAIN),
            'wc-pending' => 'Pending',
            'wc-processing' => 'Processing',
            'wc-completed' => 'Completed',
            'wc-cancelled' => 'Cancelled',
            'wc-refunded' => 'Refunded',
            'wc-failed' => 'Failed',
        ];
        foreach ( $opts as $k => $lbl ) {
            echo '<option value="' . esc_attr($k) . '"' . selected($status, $k, false) . '>' . esc_html($lbl) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="button" type="submit">' . esc_html__('Filter', TC_BF_TEXTDOMAIN) . '</button>';
        echo '</form>';

        // Legacy parity: fetch orders by COUPON usage (not by customer ownership).
        global $wpdb;

        // Default date range: from year start to today, like legacy.
        if ( $date_from === '' ) $date_from = date('Y-01-01');
        if ( $date_to === '' )   $date_to   = date('Y-m-d');

        $from_dt = $date_from . ' 00:00:00';
        $to_dt   = $date_to   . ' 23:59:59';

        // Status filter (optional).
        $allowed_statuses = [
            'wc-pending', 'wc-processing', 'wc-completed', 'wc-cancelled', 'wc-refunded', 'wc-failed',
        ];
        $status_sql = '';
        $status_params = [];
        if ( $status !== '' && in_array($status, $allowed_statuses, true) ) {
            $status_sql = ' AND p.post_status = %s ';
            $status_params[] = $status;
        }

        $offset = ($paged - 1) * $per_page;

        // Total count.
        $sql_count = "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items i ON p.ID = i.order_id
            WHERE p.post_type = 'shop_order'
              AND i.order_item_type = 'coupon'
              AND i.order_item_name = %s
              AND p.post_date >= %s
              AND p.post_date <= %s
              {$status_sql}
        ";

        $count_params = array_merge([ $partner_code_norm, $from_dt, $to_dt ], $status_params);
        $total = (int) $wpdb->get_var( $wpdb->prepare( $sql_count, $count_params ) );
        $max_num_pages = max(1, (int) ceil($total / $per_page));

        // Page IDs.
        $sql_ids = "
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}woocommerce_order_items i ON p.ID = i.order_id
            WHERE p.post_type = 'shop_order'
              AND i.order_item_type = 'coupon'
              AND i.order_item_name = %s
              AND p.post_date >= %s
              AND p.post_date <= %s
              {$status_sql}
            ORDER BY p.ID DESC
            LIMIT %d OFFSET %d
        ";

        $ids_params = array_merge([ $partner_code_norm, $from_dt, $to_dt ], $status_params, [ $per_page, $offset ]);
        $order_ids = (array) $wpdb->get_col( $wpdb->prepare( $sql_ids, $ids_params ) );

        $orders = [];
        foreach ( $order_ids as $oid ) {
            $o = wc_get_order( (int) $oid );
            if ( $o ) $orders[] = $o;
        }

        if ( empty($orders) ) {
            echo '<p>' . esc_html__('No orders found for this partner.', TC_BF_TEXTDOMAIN) . '</p>';
            return;
        }

        // Follow Woo global price display setting: include or exclude tax.
        $prices_inc_tax = function_exists('wc_prices_include_tax') ? (bool) wc_prices_include_tax() : true;

        // Convert stored net amounts to display amounts, using the order's effective tax ratio when available.
        $to_display_amount = function( \WC_Order $order, float $amount_net ) use ( $prices_inc_tax ) : float {
            if ( ! $prices_inc_tax ) {
                return $amount_net;
            }

            $total_gross = (float) $order->get_total();
            $total_tax   = (float) $order->get_total_tax();
            $total_net   = max(0.0, $total_gross - $total_tax);

            if ( $total_net <= 0.0 || $total_tax <= 0.0 ) {
                return $amount_net;
            }

            $rate = $total_tax / $total_net;
            return $amount_net * (1.0 + $rate);
        };

        // Table (legacy parity columns)
        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Order', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . esc_html__('Type', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . esc_html__('Date', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . esc_html__('Products / Services', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . esc_html__('Client total', TC_BF_TEXTDOMAIN) . ' ' . esc_html( $prices_inc_tax ? __('(incl. tax)', TC_BF_TEXTDOMAIN) : __('(excl. tax)', TC_BF_TEXTDOMAIN) ) . '</th>';
        echo '<th>' . esc_html__('Discount', TC_BF_TEXTDOMAIN) . ' ' . esc_html( $prices_inc_tax ? __('(incl. tax)', TC_BF_TEXTDOMAIN) : __('(excl. tax)', TC_BF_TEXTDOMAIN) ) . '</th>';
        echo '<th>' . esc_html__('Status', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . esc_html__('Commission', TC_BF_TEXTDOMAIN) . ' ' . esc_html( $prices_inc_tax ? __('(incl. tax)', TC_BF_TEXTDOMAIN) : __('(excl. tax)', TC_BF_TEXTDOMAIN) ) . '</th>';
        echo '</tr></thead><tbody>';

        $sum_comm = 0.0; // Display sum, excluding cancelled

        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $oid = $order->get_id();
            $order_user_id = (int) $order->get_user_id();
            $is_own_order = $order_user_id > 0 && $order_user_id === (int) $uid;

            $order_total = (float) $order->get_total();
            $total_discount = (float) $order->get_total_discount();

            // Effective tax ratio (used only for legacy conversion when shop displays prices excluding tax).
            $eff_rate = 0.0;
            $order_tax = (float) $order->get_total_tax();
            $order_net = max(0.0, $order_total - $order_tax);
            if ( $order_net > 0.0 && $order_tax > 0.0 ) {
                $eff_rate = $order_tax / $order_net;
            }
            $client_total = (float) $order->get_meta('client_total', true);
            if ( $client_total <= 0 ) $client_total = $order_total;

            $partner_commission_meta = (float) $order->get_meta('partner_commission', true);
            $client_discount_meta    = (float) $order->get_meta('client_discount', true);
            $partner_base_total_meta = (float) $order->get_meta('partner_base_total', true);
            $ledger_v = (string) $order->get_meta('tc_ledger_version', true);

            $commission_display = 0.0; // display currency (no VAT conversions here)
            $discount_display   = 0.0;

            // New ledger-driven path (v2): never infer from Woo discount buckets.
            if ( $ledger_v === '2' || $client_discount_meta > 0 || $partner_base_total_meta > 0 ) {
                $commission_display = $partner_commission_meta > 0 ? $partner_commission_meta : ($order_total * ($partner_pct / 100.0));
                if ( $client_discount_meta > 0 ) {
                    $discount_display = $client_discount_meta;
                } elseif ( $partner_base_total_meta > 0 && $client_total > 0 ) {
                    $discount_display = max(0.0, $partner_base_total_meta - $client_total);
                } else {
                    $discount_display = (float) $order->get_total_discount();
                }
            } else {
                // Legacy inference (older orders): VAT heuristics used historically.
                $commission_display = 0.0; // VAT-included
                $discount_display = 0.0;   // VAT-included

                if ( $partner_commission_meta > 0 ) {
                    $commission_display = $partner_commission_meta;
                    $commission_net = $partner_commission_meta / 1.21;
                    $discount_net = max(0.0, $total_discount - $commission_net);
                    $discount_display = $discount_net * 1.21;
                } else {
                    $commission_net = $order_total * ($partner_pct / 100.0);
                    $commission_display = $commission_net * 1.21;
                    $discount_display = $total_discount * 1.21;
                }
            }

            $status_slug = $order->get_status();



            $view_url = $order->get_view_order_url();
            echo '<tr>';
            // Order column: only link if it's the user's own order.
            if ( $is_own_order ) {
                echo '<td data-title="Order"><a href="' . esc_url($view_url) . '">#' . esc_html($oid) . '</a></td>';
            } else {
                echo '<td data-title="Order">#' . esc_html($oid) . '</td>';
            }

            echo '<td data-title="Type">' . esc_html( $is_own_order ? 'Partner order' : 'QR' ) . '</td>';
            echo '<td data-title="Date">' . esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n('d/m/Y') : '' ) . '</td>';

            // Products / Services
            $lines = [];
            foreach ( $order->get_items() as $item_id => $item ) {
                if ( ! $item instanceof \WC_Order_Item_Product ) continue;
                $line = esc_html( $item->get_name() );

                $event_id = (int) $item->get_meta('_event_id', true);
                if ( $event_id ) {
                    $event_url = get_permalink($event_id);
                    if ( ! $event_url ) {
                        $event_url = add_query_arg('page_id', (string) $event_id, home_url('/') );
                    }
                    $line .= ' &nbsp; <a href="' . esc_url($event_url) . '" target="_blank">(' . esc_html__('event', TC_BF_TEXTDOMAIN) . ')</a>';
                }

                // Booking start date (if Bookings is active)
                if ( class_exists('WC_Booking_Data_Store') ) {
                    $booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
                    if ( ! empty($booking_ids) ) {
                        $b = new \WC_Booking( (int) $booking_ids[0] );
                        $start = $b ? $b->get_start_date() : '';
                        if ( $start ) {
                            $line .= ' &nbsp; <strong>' . esc_html__('Start:', TC_BF_TEXTDOMAIN) . '</strong> ' . esc_html( date_i18n('d/m/Y', strtotime($start)) );
                        }
                    }
                }

                $lines[] = $line;
            }
            echo '<td data-title="Products / Services">' . implode('<br>', $lines) . '</td>';

            // Display amounts follow Woo tax display setting.
            // NOTE: During the migration away from the legacy offline gateway, some orders ended up
            // storing ledger amounts as GROSS (VAT-included), while newer logic may store NET.
            // We use a safe heuristic: if client_total meta ~= order total, treat ledger values as GROSS.
            $client_total_disp = $client_total;
            $discount_disp     = $discount_display;
            $commission_disp   = $commission_display;

            if ( $ledger_v === '2' || $client_discount_meta > 0 || $partner_base_total_meta > 0 ) {
                $is_gross_ledger = ( abs($client_total - $order_total) < 0.02 );

                if ( $prices_inc_tax ) {
                    // Shop displays gross.
                    if ( ! $is_gross_ledger ) {
                        // Ledger is net -> convert to gross.
                        $client_total_disp = $to_display_amount( $order, (float) $client_total );
                        $discount_disp     = $to_display_amount( $order, (float) $discount_display );
                        $commission_disp   = $to_display_amount( $order, (float) $commission_display );
                    }
                } else {
                    // Shop displays net.
                    if ( $is_gross_ledger && $eff_rate > 0.0 ) {
                        // Ledger is gross -> convert to net.
                        $client_total_disp = (float) $client_total / (1.0 + $eff_rate);
                        $discount_disp     = (float) $discount_display / (1.0 + $eff_rate);
                        $commission_disp   = (float) $commission_display / (1.0 + $eff_rate);
                    }
                }
            } else {
                // Legacy inference historically produced VAT-included amounts. Convert to net if shop displays excluding tax.
                if ( ! $prices_inc_tax && $eff_rate > 0.0 ) {
                    $client_total_disp = (float) $client_total / (1.0 + $eff_rate);
                    $discount_disp     = (float) $discount_display / (1.0 + $eff_rate);
                    $commission_disp   = (float) $commission_display / (1.0 + $eff_rate);
                }
            }

            if ( $status_slug !== 'cancelled' ) {
                $sum_comm += (float) $commission_disp;
            }

            echo '<td data-title="Client total">' . wp_kses_post( wc_price($client_total_disp) ) . '</td>';
            echo '<td data-title="Discount">' . wp_kses_post( wc_price($discount_disp) ) . '</td>';
            echo '<td data-title="Status">' . esc_html( wc_get_order_status_name( $status_slug ) ) . '</td>';
            echo '<td data-title="Commission">' . wp_kses_post( wc_price($commission_disp) ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody>';

        // Footer totals
        echo '<tfoot>';
        echo '<tr>';
        echo '<th colspan="7" style="text-align:right;">' . esc_html__('Total commission (excl. cancelled)', TC_BF_TEXTDOMAIN) . '</th>';
        echo '<th>' . wp_kses_post( wc_price($sum_comm) ) . '</th>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';

        // Pagination
        if ( $max_num_pages > 1 ) {
            echo '<nav class="woocommerce-pagination" style="margin-top:16px;">';
            for ( $p = 1; $p <= $max_num_pages; $p++ ) {
                $url = add_query_arg( [
                    'tc_paged' => $p,
                    'tc_from' => $date_from,
                    'tc_to' => $date_to,
                    'tc_status' => $status,
                ], wc_get_account_endpoint_url(self::ENDPOINT) );
                $class = $p === $paged ? ' class="page-numbers current"' : ' class="page-numbers"';
                echo '<a' . $class . ' href="' . esc_url($url) . '">' . esc_html((string)$p) . '</a> ';
            }
            echo '</nav>';
        }
    }
}
