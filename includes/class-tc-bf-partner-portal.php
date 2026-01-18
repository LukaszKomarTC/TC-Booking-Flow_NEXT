<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner Portal v2 — Meta-based filtering gated by coupon at checkout
 *
 * Endpoint: /my-account/partner/
 *
 * Policy (locked):
 * An order is partner-attributed ONLY if, at checkout/order creation time,
 * an applied coupon code exactly matches the partner's own code (discount__code).
 * Coupon removed before checkout = opt-out; no attribution, no payout.
 *
 * Portal queries by order meta `partner_id` (written at checkout by Woo_OrderMeta).
 * No fallback to legacy coupon JOINs — v2 is authoritative for new bookings only.
 *
 * Order meta used:
 * - partner_id          : WP user ID of attributed partner
 * - partner_code        : coupon code that matched at checkout
 * - partner_commission  : frozen commission amount
 * - partner_base_total  : base total before partner discount
 * - client_total        : what client paid (after discount)
 * - client_discount     : discount amount given to client
 * - tc_ledger_version   : '2' for v2 orders
 *
 * @since 0.6.0
 */
final class Partner_Portal {

    const ENDPOINT       = 'partner';
    const CSV_ACTION     = 'tcbf_partner_export_csv';
    const CSV_NONCE      = 'tcbf_partner_csv_nonce';

    /**
     * Base payable statuses for commission totals (always available)
     */
    const BASE_PAYABLE_STATUSES = [ 'processing', 'completed' ];

    /**
     * Check if the custom 'invoiced' status is registered on this site
     */
    private static function has_invoiced_status() : bool {
        if ( ! function_exists( 'wc_get_order_statuses' ) ) {
            return false;
        }
        return array_key_exists( 'wc-invoiced', wc_get_order_statuses() );
    }

    /**
     * Get payable statuses (includes 'invoiced' only if it exists)
     */
    private static function get_payable_statuses() : array {
        $statuses = self::BASE_PAYABLE_STATUSES;
        if ( self::has_invoiced_status() ) {
            $statuses[] = 'invoiced';
        }
        return $statuses;
    }

    /**
     * Get all visible statuses for portal query
     */
    private static function get_visible_statuses() : array {
        $statuses = [ 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ];
        if ( self::has_invoiced_status() ) {
            // Insert after 'completed'
            $pos = array_search( 'completed', $statuses, true );
            array_splice( $statuses, $pos + 1, 0, 'invoiced' );
        }
        return $statuses;
    }

    public static function init() : void {
        // Register endpoint
        add_action('init', [ __CLASS__, 'add_endpoint' ]);

        // Add menu item
        add_filter('woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ], 40, 1);

        // Render endpoint content
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [ __CLASS__, 'render_endpoint' ]);

        // CSV export handler
        add_action('admin_post_' . self::CSV_ACTION, [ __CLASS__, 'handle_csv_export' ]);
    }

    public static function add_endpoint() : void {
        if ( function_exists('add_rewrite_endpoint') ) {
            add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
        }
    }

    /**
     * Who can see the partner portal.
     * - administrator
     * - hotel role
     */
    private static function current_user_can_view() : bool {
        if ( ! is_user_logged_in() ) return false;

        if ( current_user_can('manage_options') ) return true;

        $u = wp_get_current_user();
        if ( ! $u || empty($u->roles) ) return false;

        return in_array('hotel', (array) $u->roles, true );
    }

    public static function add_menu_item( array $items ) : array {
        if ( ! self::current_user_can_view() ) return $items;

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

    /**
     * Check if status is payable (counts toward commission totals)
     */
    private static function is_payable_status( string $status ) : bool {
        $status = str_replace( 'wc-', '', $status );
        return in_array( $status, self::get_payable_statuses(), true );
    }

    /**
     * Build query arguments for wc_get_orders
     *
     * @param array $filters Associative array with: date_from, date_to, status
     * @param int   $user_id Partner user ID
     * @param int   $limit   Results per page (0 = unlimited)
     * @param int   $page    Page number (1-indexed)
     * @return array Query args for wc_get_orders
     */
    private static function build_query_args( array $filters, int $user_id, int $limit = 30, int $page = 1 ) : array {
        $args = [
            'limit'      => $limit > 0 ? $limit : -1,
            'paged'      => $page,
            'orderby'    => 'ID',
            'order'      => 'DESC',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => 'partner_id',
                    'value'   => (string) $user_id,
                    'compare' => '=',
                ],
                [
                    'key'     => 'tc_ledger_version',
                    'value'   => '2',
                    'compare' => '=',
                ],
            ],
        ];

        // Date range filter
        $date_from = $filters['date_from'] ?? '';
        $date_to   = $filters['date_to'] ?? '';

        if ( $date_from !== '' || $date_to !== '' ) {
            $date_query = [];

            if ( $date_from !== '' ) {
                $date_query['after'] = $date_from . ' 00:00:00';
            }
            if ( $date_to !== '' ) {
                $date_query['before'] = $date_to . ' 23:59:59';
            }
            $date_query['inclusive'] = true;

            $args['date_created'] = $date_from . '...' . $date_to;
        }

        // Status filter
        $status = $filters['status'] ?? '';
        if ( $status !== '' ) {
            $args['status'] = str_replace( 'wc-', '', $status );
        } else {
            // All visible statuses (includes 'invoiced' only if registered)
            $args['status'] = self::get_visible_statuses();
        }

        return $args;
    }

    /**
     * Get orders for partner using meta-based query
     *
     * @param array $filters Filters array
     * @param int   $user_id Partner user ID
     * @param int   $limit   Results per page
     * @param int   $page    Page number
     * @return array [ 'orders' => WC_Order[], 'total' => int ]
     */
    private static function get_orders( array $filters, int $user_id, int $limit = 30, int $page = 1 ) : array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [ 'orders' => [], 'total' => 0 ];
        }

        $args = self::build_query_args( $filters, $user_id, $limit, $page );

        // Get paginated results
        $args['paginate'] = true;
        $results = wc_get_orders( $args );

        $orders = [];
        $total  = 0;

        if ( is_object( $results ) && isset( $results->orders ) ) {
            $orders = $results->orders;
            $total  = (int) $results->total;
        } elseif ( is_array( $results ) ) {
            $orders = $results;
            // For non-paginated, get total count separately
            $count_args = self::build_query_args( $filters, $user_id, -1, 1 );
            $count_args['return'] = 'ids';
            $all_ids = wc_get_orders( $count_args );
            $total = is_array( $all_ids ) ? count( $all_ids ) : 0;
        }

        return [
            'orders' => $orders,
            'total'  => $total,
        ];
    }

    /**
     * Format a single order row for display/export
     *
     * @param \WC_Order $order         Order object
     * @param int       $partner_uid   Partner user ID (for "own order" detection)
     * @param float     $partner_pct   Partner commission % (fallback)
     * @param bool      $prices_inc_tax Whether prices include tax
     * @return array Formatted row data
     */
    private static function format_row( \WC_Order $order, int $partner_uid, float $partner_pct, bool $prices_inc_tax ) : array {
        $oid = $order->get_id();
        $order_user_id = (int) $order->get_user_id();
        $is_own_order = $order_user_id > 0 && $order_user_id === $partner_uid;

        $order_total = (float) $order->get_total();
        $total_discount = (float) $order->get_total_discount();

        // Effective tax ratio
        $eff_rate = 0.0;
        $order_tax = (float) $order->get_total_tax();
        $order_net = max( 0.0, $order_total - $order_tax );
        if ( $order_net > 0.0 && $order_tax > 0.0 ) {
            $eff_rate = $order_tax / $order_net;
        }

        // Read ledger meta
        $client_total            = (float) $order->get_meta( 'client_total', true );
        $partner_commission_meta = (float) $order->get_meta( 'partner_commission', true );
        $client_discount_meta    = (float) $order->get_meta( 'client_discount', true );
        $partner_base_total_meta = (float) $order->get_meta( 'partner_base_total', true );
        $ledger_v                = (string) $order->get_meta( 'tc_ledger_version', true );
        $partner_code_meta       = (string) $order->get_meta( 'partner_code', true );

        if ( $client_total <= 0 ) {
            $client_total = $order_total;
        }

        // Calculate display amounts
        $commission_display = 0.0;
        $discount_display   = 0.0;

        if ( $ledger_v === '2' || $client_discount_meta > 0 || $partner_base_total_meta > 0 ) {
            // v2 ledger path
            $commission_display = $partner_commission_meta > 0
                ? $partner_commission_meta
                : ( $order_total * ( $partner_pct / 100.0 ) );

            if ( $client_discount_meta > 0 ) {
                $discount_display = $client_discount_meta;
            } elseif ( $partner_base_total_meta > 0 && $client_total > 0 ) {
                $discount_display = max( 0.0, $partner_base_total_meta - $client_total );
            } else {
                $discount_display = $total_discount;
            }
        } else {
            // Legacy inference (older orders without v2 ledger)
            if ( $partner_commission_meta > 0 ) {
                $commission_display = $partner_commission_meta;
                $commission_net = $partner_commission_meta / 1.21;
                $discount_net = max( 0.0, $total_discount - $commission_net );
                $discount_display = $discount_net * 1.21;
            } else {
                $commission_net = $order_total * ( $partner_pct / 100.0 );
                $commission_display = $commission_net * 1.21;
                $discount_display = $total_discount * 1.21;
            }
        }

        $status_slug = $order->get_status();

        // Apply tax display conversion
        $client_total_disp = $client_total;
        $discount_disp     = $discount_display;
        $commission_disp   = $commission_display;

        if ( $ledger_v === '2' || $client_discount_meta > 0 || $partner_base_total_meta > 0 ) {
            $is_gross_ledger = ( abs( $client_total - $order_total ) < 0.02 );

            if ( $prices_inc_tax ) {
                if ( ! $is_gross_ledger ) {
                    // Convert net to gross
                    $client_total_disp = self::apply_tax_rate( $client_total, $eff_rate );
                    $discount_disp     = self::apply_tax_rate( $discount_display, $eff_rate );
                    $commission_disp   = self::apply_tax_rate( $commission_display, $eff_rate );
                }
            } else {
                if ( $is_gross_ledger && $eff_rate > 0.0 ) {
                    // Convert gross to net
                    $client_total_disp = $client_total / ( 1.0 + $eff_rate );
                    $discount_disp     = $discount_display / ( 1.0 + $eff_rate );
                    $commission_disp   = $commission_display / ( 1.0 + $eff_rate );
                }
            }
        } else {
            // Legacy: VAT-included amounts
            if ( ! $prices_inc_tax && $eff_rate > 0.0 ) {
                $client_total_disp = $client_total / ( 1.0 + $eff_rate );
                $discount_disp     = $discount_display / ( 1.0 + $eff_rate );
                $commission_disp   = $commission_display / ( 1.0 + $eff_rate );
            }
        }

        // Products/services description
        $products_desc = self::format_products( $order );

        return [
            'order_id'        => $oid,
            'date'            => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'd/m/Y' ) : '',
            'date_iso'        => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : '',
            'type'            => $is_own_order ? 'Partner order' : 'QR',
            'is_own_order'    => $is_own_order,
            'products'        => $products_desc,
            'client_total'    => $client_total_disp,
            'discount'        => $discount_disp,
            'status'          => $status_slug,
            'status_label'    => wc_get_order_status_name( $status_slug ),
            'commission'      => $commission_disp,
            'is_payable'      => self::is_payable_status( $status_slug ),
            'partner_code'    => $partner_code_meta,
            'ledger_version'  => $ledger_v,
            'view_url'        => $order->get_view_order_url(),
        ];
    }

    /**
     * Apply tax rate to amount
     */
    private static function apply_tax_rate( float $amount, float $rate ) : float {
        if ( $rate <= 0.0 ) return $amount;
        return $amount * ( 1.0 + $rate );
    }

    /**
     * Format products/services for an order
     */
    private static function format_products( \WC_Order $order ) : string {
        $lines = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) continue;

            $line = $item->get_name();

            $event_id = (int) $item->get_meta( '_event_id', true );
            if ( $event_id > 0 ) {
                $event_title = get_the_title( $event_id );
                if ( $event_title ) {
                    $line .= ' [' . $event_title . ']';
                }
            }

            // Booking start date
            if ( class_exists( 'WC_Booking_Data_Store' ) ) {
                $booking_ids = \WC_Booking_Data_Store::get_booking_ids_from_order_item_id( $item_id );
                if ( ! empty( $booking_ids ) ) {
                    $b = new \WC_Booking( (int) $booking_ids[0] );
                    $start = $b ? $b->get_start_date() : '';
                    if ( $start ) {
                        $line .= ' (Start: ' . date_i18n( 'd/m/Y', strtotime( $start ) ) . ')';
                    }
                }
            }

            $lines[] = $line;
        }

        return implode( '; ', $lines );
    }

    /**
     * Compute statistics from formatted rows
     *
     * @param array $rows Array of formatted row data
     * @return array Stats: visible_count, payable_count, payable_commission_sum, visible_commission_sum
     */
    private static function compute_stats( array $rows ) : array {
        $stats = [
            'visible_count'           => count( $rows ),
            'payable_count'           => 0,
            'payable_commission_sum'  => 0.0,
            'visible_commission_sum'  => 0.0,
        ];

        foreach ( $rows as $row ) {
            $stats['visible_commission_sum'] += (float) $row['commission'];

            if ( $row['is_payable'] ) {
                $stats['payable_count']++;
                $stats['payable_commission_sum'] += (float) $row['commission'];
            }
        }

        return $stats;
    }

    /**
     * Render the partner portal endpoint
     */
    public static function render_endpoint() : void {
        if ( ! self::current_user_can_view() ) {
            echo '<p>' . esc_html__( 'You do not have access to this page.', TC_BF_TEXTDOMAIN ) . '</p>';
            return;
        }

        $uid = get_current_user_id();
        $partner_code = trim( (string) get_user_meta( $uid, 'discount__code', true ) );
        $partner_pct  = (float) get_user_meta( $uid, 'usrdiscount', true );
        $partner_code_norm = function_exists( 'wc_format_coupon_code' )
            ? wc_format_coupon_code( $partner_code )
            : strtolower( $partner_code );

        if ( $partner_code_norm === '' ) {
            echo '<p>' . esc_html__( 'No partner code linked to your user.', TC_BF_TEXTDOMAIN ) . '</p>';
            return;
        }

        // Filters
        $date_from = isset( $_GET['tc_from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_from'] ) ) : '';
        $date_to   = isset( $_GET['tc_to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_to'] ) ) : '';
        $status    = isset( $_GET['tc_status'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_status'] ) ) : '';

        // Default date range: year start to today
        if ( $date_from === '' ) $date_from = date( 'Y-01-01' );
        if ( $date_to === '' )   $date_to   = date( 'Y-m-d' );

        // Pagination
        $paged    = isset( $_GET['tc_paged'] ) ? max( 1, (int) $_GET['tc_paged'] ) : 1;
        $per_page = 30;

        $filters = [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'status'    => $status,
        ];

        // Header
        echo '<h3>' . esc_html__( 'Partner report', TC_BF_TEXTDOMAIN ) . '</h3>';
        echo '<p style="margin:0 0 12px;">';
        echo '<strong>' . esc_html__( 'Partner code:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( $partner_code_norm );
        echo ' &nbsp; <strong>' . esc_html__( 'Commission:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( number_format_i18n( $partner_pct, 2 ) ) . '%';
        echo '</p>';

        // Filter form
        self::render_filter_form( $date_from, $date_to, $status );

        // v2-only note
        echo '<p style="font-size:0.85em;color:#666;margin:0 0 12px;">';
        echo esc_html__( 'This report shows orders attributed at checkout (ledger v2). Orders without partner attribution or older bookings may not appear.', TC_BF_TEXTDOMAIN );
        echo '</p>';

        // Get orders via meta query
        $result = self::get_orders( $filters, $uid, $per_page, $paged );
        $orders = $result['orders'];
        $total  = $result['total'];
        $max_num_pages = max( 1, (int) ceil( $total / $per_page ) );

        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'No orders found for this partner.', TC_BF_TEXTDOMAIN ) . '</p>';
            return;
        }

        // Format rows
        $prices_inc_tax = function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;
        $rows = [];
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $rows[] = self::format_row( $order, $uid, $partner_pct, $prices_inc_tax );
        }

        // Compute stats (for this page)
        // Note: for accurate totals across all pages, we'd need to query all orders
        // For now, show page stats + indicate if there are more pages
        $page_stats = self::compute_stats( $rows );

        // For accurate total stats, get all orders (without pagination)
        $all_result = self::get_orders( $filters, $uid, 0, 1 );
        $all_rows = [];
        foreach ( $all_result['orders'] as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $all_rows[] = self::format_row( $order, $uid, $partner_pct, $prices_inc_tax );
        }
        $total_stats = self::compute_stats( $all_rows );

        // Render table
        self::render_table( $rows, $total_stats, $prices_inc_tax );

        // CSV export button
        self::render_csv_button( $filters );

        // Pagination
        if ( $max_num_pages > 1 ) {
            self::render_pagination( $paged, $max_num_pages, $filters );
        }
    }

    /**
     * Render filter form
     */
    private static function render_filter_form( string $date_from, string $date_to, string $status ) : void {
        // Build status options dynamically (only include 'invoiced' if registered)
        $opts = [
            ''              => __( 'Any', TC_BF_TEXTDOMAIN ),
            'wc-pending'    => 'Pending',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
        ];

        if ( self::has_invoiced_status() ) {
            $opts['wc-invoiced'] = 'Invoiced';
        }

        $opts['wc-cancelled'] = 'Cancelled';
        $opts['wc-refunded']  = 'Refunded';
        $opts['wc-failed']    = 'Failed';

        echo '<form method="get" action="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '" style="margin: 0 0 16px;">';
        echo '<label style="margin-right:12px;">' . esc_html__( 'From', TC_BF_TEXTDOMAIN ) . ' <input type="date" name="tc_from" value="' . esc_attr( $date_from ) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__( 'To', TC_BF_TEXTDOMAIN ) . ' <input type="date" name="tc_to" value="' . esc_attr( $date_to ) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__( 'Status', TC_BF_TEXTDOMAIN ) . ' <select name="tc_status">';
        foreach ( $opts as $k => $lbl ) {
            echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $lbl ) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="button" type="submit">' . esc_html__( 'Filter', TC_BF_TEXTDOMAIN ) . '</button>';
        echo '</form>';
    }

    /**
     * Render orders table
     */
    private static function render_table( array $rows, array $stats, bool $prices_inc_tax ) : void {
        $tax_label = $prices_inc_tax ? __( '(incl. tax)', TC_BF_TEXTDOMAIN ) : __( '(excl. tax)', TC_BF_TEXTDOMAIN );

        echo '<table class="shop_table shop_table_responsive my_account_orders">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Order', TC_BF_TEXTDOMAIN ) . '</th>';
        echo '<th>' . esc_html__( 'Type', TC_BF_TEXTDOMAIN ) . '</th>';
        echo '<th>' . esc_html__( 'Date', TC_BF_TEXTDOMAIN ) . '</th>';
        echo '<th>' . esc_html__( 'Products / Services', TC_BF_TEXTDOMAIN ) . '</th>';
        echo '<th>' . esc_html__( 'Client total', TC_BF_TEXTDOMAIN ) . ' ' . esc_html( $tax_label ) . '</th>';
        echo '<th>' . esc_html__( 'Discount', TC_BF_TEXTDOMAIN ) . ' ' . esc_html( $tax_label ) . '</th>';
        echo '<th>' . esc_html__( 'Status', TC_BF_TEXTDOMAIN ) . '</th>';
        echo '<th>' . esc_html__( 'Commission', TC_BF_TEXTDOMAIN ) . ' ' . esc_html( $tax_label ) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ( $rows as $row ) {
            echo '<tr>';

            // Order ID (link only if own order)
            if ( $row['is_own_order'] ) {
                echo '<td data-title="Order"><a href="' . esc_url( $row['view_url'] ) . '">#' . esc_html( $row['order_id'] ) . '</a></td>';
            } else {
                echo '<td data-title="Order">#' . esc_html( $row['order_id'] ) . '</td>';
            }

            echo '<td data-title="Type">' . esc_html( $row['type'] ) . '</td>';
            echo '<td data-title="Date">' . esc_html( $row['date'] ) . '</td>';
            echo '<td data-title="Products / Services">' . esc_html( $row['products'] ) . '</td>';
            echo '<td data-title="Client total">' . wp_kses_post( wc_price( $row['client_total'] ) ) . '</td>';
            echo '<td data-title="Discount">' . wp_kses_post( wc_price( $row['discount'] ) ) . '</td>';
            echo '<td data-title="Status">' . esc_html( $row['status_label'] ) . '</td>';
            echo '<td data-title="Commission">' . wp_kses_post( wc_price( $row['commission'] ) ) . '</td>';

            echo '</tr>';
        }

        echo '</tbody>';

        // Footer with totals
        echo '<tfoot>';
        echo '<tr>';
        echo '<th colspan="7" style="text-align:right;">';
        echo esc_html__( 'Total payable commission', TC_BF_TEXTDOMAIN );
        echo ' <small>(' . esc_html( sprintf(
            __( '%d of %d orders', TC_BF_TEXTDOMAIN ),
            $stats['payable_count'],
            $stats['visible_count']
        ) ) . ')</small>';
        echo '</th>';
        echo '<th>' . wp_kses_post( wc_price( $stats['payable_commission_sum'] ) ) . '</th>';
        echo '</tr>';
        echo '<tr style="font-size:0.9em;opacity:0.8;">';
        echo '<td colspan="7" style="text-align:right;">';
        echo esc_html__( 'Payable statuses:', TC_BF_TEXTDOMAIN ) . ' ';
        echo esc_html( implode( ', ', array_map( 'wc_get_order_status_name', self::get_payable_statuses() ) ) );
        echo '</td>';
        echo '<td></td>';
        echo '</tr>';
        echo '</tfoot>';
        echo '</table>';
    }

    /**
     * Render CSV export button
     */
    private static function render_csv_button( array $filters ) : void {
        $nonce = wp_create_nonce( self::CSV_NONCE );

        $export_url = add_query_arg( [
            'action'    => self::CSV_ACTION,
            '_wpnonce'  => $nonce,
            'tc_from'   => $filters['date_from'],
            'tc_to'     => $filters['date_to'],
            'tc_status' => $filters['status'],
        ], admin_url( 'admin-post.php' ) );

        echo '<p style="margin-top:16px;">';
        echo '<a href="' . esc_url( $export_url ) . '" class="button">';
        echo esc_html__( 'Download CSV', TC_BF_TEXTDOMAIN );
        echo '</a>';
        echo '</p>';
    }

    /**
     * Render pagination
     */
    private static function render_pagination( int $paged, int $max_num_pages, array $filters ) : void {
        echo '<nav class="woocommerce-pagination" style="margin-top:16px;">';

        for ( $p = 1; $p <= $max_num_pages; $p++ ) {
            $url = add_query_arg( [
                'tc_paged'  => $p,
                'tc_from'   => $filters['date_from'],
                'tc_to'     => $filters['date_to'],
                'tc_status' => $filters['status'],
            ], wc_get_account_endpoint_url( self::ENDPOINT ) );

            $class = $p === $paged ? ' class="page-numbers current"' : ' class="page-numbers"';
            echo '<a' . $class . ' href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
        }

        echo '</nav>';
    }

    /**
     * Handle CSV export request
     */
    public static function handle_csv_export() : void {
        // Security checks
        if ( ! is_user_logged_in() ) {
            wp_die( 'Unauthorized', 'Error', [ 'response' => 403 ] );
        }

        if ( ! self::current_user_can_view() ) {
            wp_die( 'Access denied', 'Error', [ 'response' => 403 ] );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], self::CSV_NONCE ) ) {
            wp_die( 'Invalid nonce', 'Error', [ 'response' => 403 ] );
        }

        $uid = get_current_user_id();
        $partner_code = trim( (string) get_user_meta( $uid, 'discount__code', true ) );
        $partner_pct  = (float) get_user_meta( $uid, 'usrdiscount', true );

        if ( $partner_code === '' ) {
            wp_die( 'No partner code', 'Error', [ 'response' => 400 ] );
        }

        // Filters
        $date_from = isset( $_GET['tc_from'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_from'] ) ) : '';
        $date_to   = isset( $_GET['tc_to'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_to'] ) ) : '';
        $status    = isset( $_GET['tc_status'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['tc_status'] ) ) : '';

        if ( $date_from === '' ) $date_from = date( 'Y-01-01' );
        if ( $date_to === '' )   $date_to   = date( 'Y-m-d' );

        $filters = [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'status'    => $status,
        ];

        // Get all orders (no pagination for export)
        $result = self::get_orders( $filters, $uid, 0, 1 );
        $orders = $result['orders'];

        $prices_inc_tax = function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;

        // Format rows
        $rows = [];
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $rows[] = self::format_row( $order, $uid, $partner_pct, $prices_inc_tax );
        }

        $stats = self::compute_stats( $rows );

        // Output CSV
        $filename = 'partner-orders-' . date( 'Ymd' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility
        fprintf( $out, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Headers
        fputcsv( $out, [
            'Order ID',
            'Date',
            'Type',
            'Products / Services',
            'Client Total',
            'Discount',
            'Status',
            'Commission',
            'Is Payable',
            'Partner Code',
        ] );

        // Data rows
        foreach ( $rows as $row ) {
            fputcsv( $out, [
                $row['order_id'],
                $row['date_iso'],
                $row['type'],
                $row['products'],
                number_format( $row['client_total'], 2, '.', '' ),
                number_format( $row['discount'], 2, '.', '' ),
                $row['status_label'],
                number_format( $row['commission'], 2, '.', '' ),
                $row['is_payable'] ? 'Yes' : 'No',
                $row['partner_code'],
            ] );
        }

        // Summary row
        fputcsv( $out, [] );
        fputcsv( $out, [
            'TOTALS',
            '',
            '',
            '',
            '',
            '',
            'Payable: ' . $stats['payable_count'] . ' / ' . $stats['visible_count'],
            number_format( $stats['payable_commission_sum'], 2, '.', '' ),
            '',
            '',
        ] );

        fclose( $out );
        exit;
    }
}
