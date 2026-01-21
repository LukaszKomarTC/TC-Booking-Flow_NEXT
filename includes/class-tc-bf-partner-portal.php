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
     * Check if current user is in admin mode (can see all partners)
     */
    private static function is_admin_mode() : bool {
        return function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' );
    }

    /**
     * Get requested partner filter from URL (admin mode only)
     * @return int Partner user ID, or 0 for "all partners"
     */
    private static function get_requested_partner_filter() : int {
        $raw = isset( $_GET['partner_filter'] ) ? (string) $_GET['partner_filter'] : '';
        $pid = (int) $raw;
        return $pid > 0 ? $pid : 0;
    }

    /**
     * Get list of partners for admin filter dropdown
     * @return array [ user_id => "Display Name (code)" ]
     */
    private static function get_partner_options() : array {
        $users = get_users( [
            'meta_key'     => 'discount__code',
            'meta_compare' => 'EXISTS',
            'orderby'      => 'display_name',
            'order'        => 'ASC',
        ] );

        $opts = [ 0 => __( 'All partners', TC_BF_TEXTDOMAIN ) ];
        foreach ( $users as $u ) {
            $code = (string) get_user_meta( $u->ID, 'discount__code', true );
            $label = $u->display_name;
            if ( $code !== '' ) {
                $label .= ' (' . $code . ')';
            }
            $opts[ (int) $u->ID ] = $label;
        }
        return $opts;
    }

    /**
     * Get payable statuses (paid-equivalent statuses for commission calculations).
     *
     * Uses Woo_StatusPolicy as the single source of truth.
     */
    private static function get_payable_statuses() : array {
        if ( class_exists( '\\TC_BF\\Integrations\\WooCommerce\\Woo_StatusPolicy' ) ) {
            return \TC_BF\Integrations\WooCommerce\Woo_StatusPolicy::get_paid_equivalent_statuses();
        }
        // Fallback if policy class not loaded
        return self::BASE_PAYABLE_STATUSES;
    }

    /**
     * Get all visible statuses for portal query
     */
    private static function get_visible_statuses() : array {
        $statuses = [ 'pending', 'processing', 'on-hold', 'completed', 'invoiced', 'cancelled', 'refunded', 'failed' ];
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
     * - administrator (manage_options)
     * - shop manager (manage_woocommerce)
     * - hotel role (partners)
     */
    private static function current_user_can_view() : bool {
        if ( ! is_user_logged_in() ) return false;

        // Admins and shop managers
        if ( current_user_can( 'manage_options' ) ) return true;
        if ( current_user_can( 'manage_woocommerce' ) ) return true;

        // Partners (hotel role)
        $u = wp_get_current_user();
        if ( ! $u || empty( $u->roles ) ) return false;

        return in_array( 'hotel', (array) $u->roles, true );
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
     * Supports two modes:
     * - Partner mode: query by partner_id = current_user_id
     * - Admin mode: query all partners (partner_filter=0) or specific partner (partner_filter>0)
     *
     * @param array $filters          Associative array with: date_from, date_to, status
     * @param int   $partner_filter   Partner to filter by (0 = all partners in admin mode)
     * @param bool  $is_admin         Whether in admin mode
     * @param int   $limit            Results per page (0 = unlimited)
     * @param int   $page             Page number (1-indexed)
     * @return array Query args for wc_get_orders
     */
    private static function build_query_args( array $filters, int $partner_filter, bool $is_admin, int $limit = 30, int $page = 1 ) : array {
        $args = [
            'limit'      => $limit > 0 ? $limit : -1,
            'paged'      => $page,
            'orderby'    => 'ID',
            'order'      => 'DESC',
        ];

        // Build meta query - always require tc_ledger_version = 2
        $meta_query = [
            'relation' => 'AND',
            [
                'key'     => 'tc_ledger_version',
                'value'   => '2',
                'compare' => '=',
            ],
        ];

        // Partner ID filter
        if ( $is_admin ) {
            // Admin mode: filter by specific partner if selected, otherwise show all
            if ( $partner_filter > 0 ) {
                $meta_query[] = [
                    'key'     => 'partner_id',
                    'value'   => (string) $partner_filter,
                    'compare' => '=',
                ];
            } else {
                // All partners: just require partner_id EXISTS (any partner-attributed order)
                $meta_query[] = [
                    'key'     => 'partner_id',
                    'compare' => 'EXISTS',
                ];
            }
        } else {
            // Partner mode: always filter by their own partner_id
            $meta_query[] = [
                'key'     => 'partner_id',
                'value'   => (string) $partner_filter,
                'compare' => '=',
            ];
        }

        $args['meta_query'] = $meta_query;

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
     * Get orders using meta-based query
     *
     * @param array $filters        Filters array
     * @param int   $partner_filter Partner to filter by (0 = all in admin mode)
     * @param bool  $is_admin       Whether in admin mode
     * @param int   $limit          Results per page
     * @param int   $page           Page number
     * @return array [ 'orders' => WC_Order[], 'total' => int ]
     */
    private static function get_orders( array $filters, int $partner_filter, bool $is_admin, int $limit = 30, int $page = 1 ) : array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [ 'orders' => [], 'total' => 0 ];
        }

        $args = self::build_query_args( $filters, $partner_filter, $is_admin, $limit, $page );

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
            $count_args = self::build_query_args( $filters, $partner_filter, $is_admin, -1, 1 );
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
     * IMPORTANT: Client-facing money (total, discount) comes from WooCommerce authoritative
     * getters, NOT from meta math. This ensures exact parity with cart/order confirmation.
     * Commission comes from ledger snapshot meta (for accounting).
     *
     * @param \WC_Order $order         Order object
     * @param int       $partner_uid   Partner user ID (for "own order" detection)
     * @param float     $partner_pct   Partner commission % (fallback)
     * @param bool      $prices_inc_tax Whether prices include tax (unused, kept for signature compat)
     * @return array Formatted row data
     */
    private static function format_row( \WC_Order $order, int $partner_uid, float $partner_pct, bool $prices_inc_tax ) : array {
        $oid = $order->get_id();
        $order_user_id = (int) $order->get_user_id();
        $is_own_order = $order_user_id > 0 && $order_user_id === $partner_uid;

        // ============================================================
        // CLIENT-FACING MONEY: Use Woo authoritative getters (already rounded)
        // ============================================================

        // Client total = what customer paid (Woo's authoritative value)
        $client_total_disp = (float) $order->get_total();

        // Discount = coupon discount including tax (Woo's authoritative value)
        $discount_disp = (float) $order->get_discount_total() + (float) $order->get_discount_tax();

        // ============================================================
        // COMMISSION: From ledger snapshot meta (for accounting)
        // ============================================================

        $partner_commission_meta = (float) $order->get_meta( 'partner_commission', true );
        $ledger_v                = (string) $order->get_meta( 'tc_ledger_version', true );
        $partner_code_meta       = (string) $order->get_meta( 'partner_code', true );

        // Commission from snapshot, with fallback to calculation if missing
        if ( $partner_commission_meta > 0 ) {
            $commission_disp = $partner_commission_meta;
        } else {
            // Fallback: calculate from order total (should rarely happen for v2 orders)
            $commission_disp = $client_total_disp * ( $partner_pct / 100.0 );
        }

        $status_slug = $order->get_status();

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
     * @deprecated No longer used - kept for backward compatibility
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
     *
     * Supports two modes:
     * - Partner mode: shows only their attributed orders
     * - Admin mode (manage_woocommerce): shows all partners or filtered by partner
     */
    public static function render_endpoint() : void {
        if ( ! self::current_user_can_view() ) {
            echo '<p>' . esc_html__( 'You do not have access to this page.', TC_BF_TEXTDOMAIN ) . '</p>';
            return;
        }

        $uid = get_current_user_id();
        $is_admin = self::is_admin_mode();

        // Determine partner filter
        // Admin: use requested filter (0 = all partners)
        // Partner: force to their own user ID (ignore URL tampering)
        if ( $is_admin ) {
            $partner_filter = self::get_requested_partner_filter();
        } else {
            // Partner mode: must have a partner code
            $partner_code = trim( (string) get_user_meta( $uid, 'discount__code', true ) );
            if ( $partner_code === '' ) {
                echo '<p>' . esc_html__( 'No partner code linked to your user.', TC_BF_TEXTDOMAIN ) . '</p>';
                return;
            }
            $partner_filter = $uid; // Force to own user ID
        }

        // Get partner info for display (for selected partner or own partner)
        $display_partner_id = $partner_filter > 0 ? $partner_filter : 0;
        $partner_code_display = '';
        $partner_pct = 0.0;

        if ( $display_partner_id > 0 ) {
            $partner_code_display = trim( (string) get_user_meta( $display_partner_id, 'discount__code', true ) );
            $partner_pct = (float) get_user_meta( $display_partner_id, 'usrdiscount', true );
            if ( function_exists( 'wc_format_coupon_code' ) ) {
                $partner_code_display = wc_format_coupon_code( $partner_code_display );
            } else {
                $partner_code_display = strtolower( $partner_code_display );
            }
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

        if ( $is_admin ) {
            // Admin header
            if ( $partner_filter > 0 ) {
                $partner_user = get_userdata( $partner_filter );
                $partner_name = $partner_user ? $partner_user->display_name : "ID #{$partner_filter}";
                echo '<p style="margin:0 0 12px;">';
                echo '<strong>' . esc_html__( 'Viewing partner:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( $partner_name );
                if ( $partner_code_display !== '' ) {
                    echo ' &nbsp; <strong>' . esc_html__( 'Code:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( $partner_code_display );
                    echo ' &nbsp; <strong>' . esc_html__( 'Commission:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( number_format_i18n( $partner_pct, 2 ) ) . '%';
                }
                echo '</p>';
            } else {
                echo '<p style="margin:0 0 12px;"><em>' . esc_html__( 'Admin view: showing all partner-attributed orders.', TC_BF_TEXTDOMAIN ) . '</em></p>';
            }
        } else {
            // Partner header
            echo '<p style="margin:0 0 12px;">';
            echo '<strong>' . esc_html__( 'Partner code:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( $partner_code_display );
            echo ' &nbsp; <strong>' . esc_html__( 'Commission:', TC_BF_TEXTDOMAIN ) . '</strong> ' . esc_html( number_format_i18n( $partner_pct, 2 ) ) . '%';
            echo '</p>';
        }

        // Filter form (with partner selector for admins)
        self::render_filter_form( $date_from, $date_to, $status, $partner_filter );

        // v2-only note
        echo '<p style="font-size:0.85em;color:#666;margin:0 0 12px;">';
        echo esc_html__( 'This report shows orders attributed at checkout (ledger v2). Orders without partner attribution or older bookings may not appear.', TC_BF_TEXTDOMAIN );
        echo '</p>';

        // Get orders via meta query
        $result = self::get_orders( $filters, $partner_filter, $is_admin, $per_page, $paged );
        $orders = $result['orders'];
        $total  = $result['total'];
        $max_num_pages = max( 1, (int) ceil( $total / $per_page ) );

        if ( empty( $orders ) ) {
            $no_orders_msg = $is_admin && $partner_filter === 0
                ? __( 'No partner-attributed orders found.', TC_BF_TEXTDOMAIN )
                : __( 'No orders found for this partner.', TC_BF_TEXTDOMAIN );
            echo '<p>' . esc_html( $no_orders_msg ) . '</p>';
            return;
        }

        // Format rows
        $prices_inc_tax = function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;
        $rows = [];
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            // For row formatting, use the partner_filter (or 0 for admin all-view)
            $row_partner_id = $display_partner_id > 0 ? $display_partner_id : 0;
            $rows[] = self::format_row( $order, $row_partner_id, $partner_pct, $prices_inc_tax );
        }

        // For accurate total stats, get all orders (without pagination)
        $all_result = self::get_orders( $filters, $partner_filter, $is_admin, 0, 1 );
        $all_rows = [];
        foreach ( $all_result['orders'] as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $row_partner_id = $display_partner_id > 0 ? $display_partner_id : 0;
            $all_rows[] = self::format_row( $order, $row_partner_id, $partner_pct, $prices_inc_tax );
        }
        $total_stats = self::compute_stats( $all_rows );

        // Render table
        self::render_table( $rows, $total_stats, $prices_inc_tax );

        // CSV export button
        self::render_csv_button( $filters, $partner_filter );

        // Pagination
        if ( $max_num_pages > 1 ) {
            self::render_pagination( $paged, $max_num_pages, $filters, $partner_filter );
        }
    }

    /**
     * Render filter form
     *
     * @param string $date_from      Selected date from
     * @param string $date_to        Selected date to
     * @param string $status         Selected status filter
     * @param int    $partner_filter Selected partner (0 = all, for admin mode)
     */
    private static function render_filter_form( string $date_from, string $date_to, string $status, int $partner_filter = 0 ) : void {
        // Build status options (invoiced is always available, registered by TCBF)
        $status_opts = [
            ''              => __( 'Any', TC_BF_TEXTDOMAIN ),
            'wc-pending'    => 'Pending',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
            'wc-invoiced'   => 'Invoiced',
            'wc-settled'    => 'Settled',
            'wc-cancelled'  => 'Cancelled',
            'wc-refunded'   => 'Refunded',
            'wc-failed'     => 'Failed',
        ];

        echo '<form method="get" action="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '" style="margin: 0 0 16px;">';

        // Partner selector (admin mode only)
        if ( self::is_admin_mode() ) {
            $partner_opts = self::get_partner_options();
            echo '<label style="margin-right:12px;">' . esc_html__( 'Partner', TC_BF_TEXTDOMAIN ) . ' <select name="partner_filter">';
            foreach ( $partner_opts as $pid => $plabel ) {
                echo '<option value="' . esc_attr( (string) $pid ) . '"' . selected( $partner_filter, $pid, false ) . '>' . esc_html( $plabel ) . '</option>';
            }
            echo '</select></label>';
        }

        echo '<label style="margin-right:12px;">' . esc_html__( 'From', TC_BF_TEXTDOMAIN ) . ' <input type="date" name="tc_from" value="' . esc_attr( $date_from ) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__( 'To', TC_BF_TEXTDOMAIN ) . ' <input type="date" name="tc_to" value="' . esc_attr( $date_to ) . '" /></label>';
        echo '<label style="margin-right:12px;">' . esc_html__( 'Status', TC_BF_TEXTDOMAIN ) . ' <select name="tc_status">';
        foreach ( $status_opts as $k => $lbl ) {
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
     *
     * @param array $filters        Current filters
     * @param int   $partner_filter Partner filter (0 = all, for admin mode)
     */
    private static function render_csv_button( array $filters, int $partner_filter = 0 ) : void {
        $nonce = wp_create_nonce( self::CSV_NONCE );

        $args = [
            'action'    => self::CSV_ACTION,
            '_wpnonce'  => $nonce,
            'tc_from'   => $filters['date_from'],
            'tc_to'     => $filters['date_to'],
            'tc_status' => $filters['status'],
        ];

        // Include partner_filter for admin mode
        if ( self::is_admin_mode() ) {
            $args['partner_filter'] = $partner_filter;
        }

        $export_url = add_query_arg( $args, admin_url( 'admin-post.php' ) );

        echo '<p style="margin-top:16px;">';
        echo '<a href="' . esc_url( $export_url ) . '" class="button">';
        echo esc_html__( 'Download CSV', TC_BF_TEXTDOMAIN );
        echo '</a>';
        echo '</p>';
    }

    /**
     * Render pagination
     *
     * @param int   $paged          Current page
     * @param int   $max_num_pages  Total pages
     * @param array $filters        Current filters
     * @param int   $partner_filter Partner filter (0 = all, for admin mode)
     */
    private static function render_pagination( int $paged, int $max_num_pages, array $filters, int $partner_filter = 0 ) : void {
        echo '<nav class="woocommerce-pagination" style="margin-top:16px;">';

        for ( $p = 1; $p <= $max_num_pages; $p++ ) {
            $args = [
                'tc_paged'  => $p,
                'tc_from'   => $filters['date_from'],
                'tc_to'     => $filters['date_to'],
                'tc_status' => $filters['status'],
            ];

            // Include partner_filter for admin mode
            if ( self::is_admin_mode() && $partner_filter > 0 ) {
                $args['partner_filter'] = $partner_filter;
            }

            $url = add_query_arg( $args, wc_get_account_endpoint_url( self::ENDPOINT ) );

            $class = $p === $paged ? ' class="page-numbers current"' : ' class="page-numbers"';
            echo '<a' . $class . ' href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
        }

        echo '</nav>';
    }

    /**
     * Handle CSV export request
     *
     * Supports two modes:
     * - Partner mode: export only their attributed orders
     * - Admin mode: export all partners or filtered by partner
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
        $is_admin = self::is_admin_mode();

        // Determine partner filter (same logic as render_endpoint)
        if ( $is_admin ) {
            $partner_filter = self::get_requested_partner_filter();
        } else {
            // Partner mode: must have a partner code
            $partner_code = trim( (string) get_user_meta( $uid, 'discount__code', true ) );
            if ( $partner_code === '' ) {
                wp_die( 'No partner code', 'Error', [ 'response' => 400 ] );
            }
            $partner_filter = $uid; // Force to own user ID
        }

        // Get partner info for display
        $display_partner_id = $partner_filter > 0 ? $partner_filter : 0;
        $partner_pct = 0.0;
        if ( $display_partner_id > 0 ) {
            $partner_pct = (float) get_user_meta( $display_partner_id, 'usrdiscount', true );
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
        $result = self::get_orders( $filters, $partner_filter, $is_admin, 0, 1 );
        $orders = $result['orders'];

        $prices_inc_tax = function_exists( 'wc_prices_include_tax' ) ? (bool) wc_prices_include_tax() : true;

        // Format rows
        $rows = [];
        foreach ( $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) continue;
            $row_partner_id = $display_partner_id > 0 ? $display_partner_id : 0;
            $rows[] = self::format_row( $order, $row_partner_id, $partner_pct, $prices_inc_tax );
        }

        $stats = self::compute_stats( $rows );

        // Build filename based on context
        $date_str = date( 'Ymd' );
        if ( $is_admin ) {
            if ( $partner_filter > 0 ) {
                $filename = "partner-orders-{$partner_filter}-{$date_str}.csv";
            } else {
                $filename = "partner-orders-all-{$date_str}.csv";
            }
        } else {
            $filename = "partner-orders-{$uid}-{$date_str}.csv";
        }

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
