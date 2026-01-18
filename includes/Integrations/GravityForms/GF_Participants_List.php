<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

use TC_BF\Domain\PartnerResolver;
use TC_BF\Domain\EventMeta;

/**
 * TCBF Native Participants List
 *
 * Replaces GravityView with a TCBF-native GFAPI renderer.
 *
 * Design Contract:
 * - TCBF owns the rules (no business logic in templates)
 * - Privacy is enforced in PHP only (no masked GF fields)
 * - Settings are the authority (not shortcode attributes)
 * - CSS is properly enqueued (no inline wp_head output)
 * - Partner authorization uses canonical TCBF resolvers (PartnerResolver, EventMeta)
 * - Partner "full view" is per-row, based on user ID ownership (not coupon code)
 *
 * @since 0.6.0
 */
final class GF_Participants_List {

	/**
	 * Centralized GF field mapping (single source of truth)
	 *
	 * All GF field IDs live here. Rendering code never references raw IDs.
	 * Missing field values render as "—", never fatal.
	 */
	private static array $field_map = [
		'first_name'  => '2.3',
		'last_name'   => '2.6',
		'email'       => '21',
		'pedals'      => '60',
		'helmet'      => '61',
	];

	/**
	 * Bicycle field IDs to try in order (fallback chain)
	 *
	 * Field 146 = derived summary text
	 * Fields 130/142/143/169 = actual radio selections per bike type
	 */
	private static array $bike_fields = [ '146', '130', '142', '143', '169' ];

	/**
	 * Option keys for plugin settings
	 */
	const OPT_PRIVACY_MODE      = 'tcbf_participants_privacy_mode';
	const OPT_EVENT_UID_FIELD   = 'tcbf_participants_event_uid_field_id';
	const OPT_USER_ID_FIELD     = 'tcbf_participants_user_id_field_id';

	/**
	 * Privacy mode constants
	 */
	const PRIVACY_PUBLIC_MASKED = 'public_masked';
	const PRIVACY_ADMIN_ONLY    = 'admin_only';
	const PRIVACY_FULL          = 'full';

	/**
	 * Entry meta key for notification ledger
	 */
	const META_NOTIF_LEDGER = 'tcbf_notif_ledger';

	/**
	 * Maximum rows to display (safety cap)
	 */
	const MAX_ROWS = 40;

	/**
	 * Register the shortcode
	 */
	public static function register() : void {
		add_shortcode( 'tcbf_participants', [ __CLASS__, 'render_shortcode' ] );
	}

	/**
	 * Enqueue participants list CSS
	 *
	 * Called on wp_enqueue_scripts, only on sc_event singles.
	 */
	public static function enqueue_assets() : void {
		if ( ! is_singular('sc_event') ) {
			return;
		}

		wp_enqueue_style(
			'tcbf-participants',
			TC_BF_URL . 'assets/css/tcbf-participants.css',
			[],
			defined('TC_BF_VERSION') ? TC_BF_VERSION : null
		);
	}

	/**
	 * Render the participants list shortcode
	 *
	 * Usage: [tcbf_participants event_id="123"]
	 *
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public static function render_shortcode( $atts ) : string {
		$atts = shortcode_atts([
			'event_id' => 0,
		], $atts, 'tcbf_participants');

		$event_id = (int) $atts['event_id'];

		// Validate event_id
		if ( $event_id <= 0 ) {
			return self::admin_debug_comment('Missing or invalid event_id');
		}

		// Compute event_uid internally (TCBF owns the rules)
		$date_time = get_post_meta( $event_id, 'sc_event_date_time', true );
		if ( empty( $date_time ) ) {
			return self::admin_debug_comment('Event date_time not found for event_id=' . $event_id);
		}

		$event_uid = $event_id . '_' . $date_time;

		// Check privacy mode and access levels
		$privacy_mode = self::get_privacy_mode();
		$is_admin = self::is_admin_user();

		// Check if partners are enabled for this event (canonical EventMeta)
		$partners_enabled = self::partners_enabled_for_event( $event_id );

		// Check if current user is a partner user
		$is_partner_user = $partners_enabled && self::is_partner_user();

		// Query entries via GFAPI
		$entries = self::query_participants( $event_uid );

		// admin_only mode handling
		if ( $privacy_mode === self::PRIVACY_ADMIN_ONLY && ! $is_admin ) {
			// Partners can see the list if enabled + is partner user
			if ( ! ( $partners_enabled && $is_partner_user ) ) {
				return '';
			}
			// Partner viewing admin_only: check if they have any owned rows
			// If no owned rows, hide the list entirely
			$has_owned_rows = false;
			foreach ( $entries as $entry ) {
				if ( self::entry_owned_by_current_user( $entry ) ) {
					$has_owned_rows = true;
					break;
				}
			}
			if ( ! $has_owned_rows ) {
				return '';
			}
		}

		if ( empty( $entries ) ) {
			return self::render_empty_state();
		}

		// Render the table with per-row masking
		return self::render_table( $entries, $privacy_mode, $is_admin, $is_partner_user, $partners_enabled );
	}

	/**
	 * Query participants from GFAPI
	 *
	 * @param string $event_uid Event unique identifier
	 * @return array Array of GF entries
	 */
	private static function query_participants( string $event_uid ) : array {
		if ( ! class_exists('GFAPI') ) {
			return [];
		}

		// Get form ID from main settings
		$form_id = (int) get_option( \TC_BF\Admin\Settings::OPT_FORM_ID, 44 );
		if ( $form_id <= 0 ) {
			return [];
		}

		// Get event UID field ID from settings
		$uid_field_id = (int) get_option( self::OPT_EVENT_UID_FIELD, 145 );
		if ( $uid_field_id <= 0 ) {
			$uid_field_id = 145;
		}

		// Build search criteria (filter by event UID only; status shown per row)
		$search_criteria = [
			'field_filters' => [
				[
					'key'      => (string) $uid_field_id,
					'value'    => $event_uid,
					'operator' => '=',
				],
			],
		];

		// Sort by date_created ASC (earliest registrations first)
		$sorting = [
			'key'       => 'date_created',
			'direction' => 'ASC',
		];

		// Cap at MAX_ROWS
		$paging = [
			'offset'    => 0,
			'page_size' => self::MAX_ROWS,
		];

		$entries = \GFAPI::get_entries( $form_id, $search_criteria, $sorting, $paging );

		if ( is_wp_error( $entries ) ) {
			\TC_BF\Support\Logger::log( 'participants_list.query_error', [
				'form_id'   => $form_id,
				'event_uid' => $event_uid,
				'error'     => $entries->get_error_message(),
			] );
			return [];
		}

		return is_array( $entries ) ? $entries : [];
	}

	/**
	 * Get the privacy mode from settings
	 *
	 * @return string Privacy mode constant
	 */
	private static function get_privacy_mode() : string {
		$mode = get_option( self::OPT_PRIVACY_MODE, self::PRIVACY_PUBLIC_MASKED );

		// Validate
		$valid_modes = [ self::PRIVACY_PUBLIC_MASKED, self::PRIVACY_ADMIN_ONLY, self::PRIVACY_FULL ];
		if ( ! in_array( $mode, $valid_modes, true ) ) {
			return self::PRIVACY_PUBLIC_MASKED;
		}

		return $mode;
	}

	/**
	 * Check if current user is admin (for privacy override)
	 *
	 * Admin override always applies: manage_options OR manage_woocommerce
	 *
	 * @return bool
	 */
	private static function is_admin_user() : bool {
		return current_user_can('manage_options') || current_user_can('manage_woocommerce');
	}

	/**
	 * Render the participants table
	 *
	 * @param array  $entries          GF entries
	 * @param string $privacy_mode     Privacy mode setting
	 * @param bool   $is_admin         Whether user is admin
	 * @param bool   $is_partner_user  Whether user is a partner
	 * @param bool   $partners_enabled Whether partners are enabled for this event
	 * @return string HTML table
	 */
	private static function render_table( array $entries, string $privacy_mode, bool $is_admin, bool $is_partner_user, bool $partners_enabled ) : string {
		// Determine if Info column should be shown (admin or partner user)
		$show_info_column = $is_admin || $is_partner_user;

		$html = '<div class="tcbf-participants-list">';
		$html .= '<table class="tcbf-participants-table">';

		// Table header
		$html .= '<thead><tr>';
		$html .= '<th class="tcbf-col-number">#</th>';
		$html .= '<th class="tcbf-col-participant">' . esc_html__('Participant', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-email">' . esc_html__('Email', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-bicycle">' . esc_html__('Bicycle + size', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-pedals">' . esc_html__('Pedals', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-helmet">' . esc_html__('Helmet', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-date">' . esc_html__('Signed up on', 'tc-booking-flow-next') . '</th>';
		$html .= '<th class="tcbf-col-status">' . esc_html__('Status', 'tc-booking-flow-next') . '</th>';

		// Info status column (admin + partner only)
		if ( $show_info_column ) {
			$html .= '<th class="tcbf-col-info">' . esc_html__('Info', 'tc-booking-flow-next') . '</th>';
		}

		$html .= '</tr></thead>';

		// Table body
		$html .= '<tbody>';
		$row_num = 0;

		foreach ( $entries as $entry ) {
			$row_num++;
			$html .= self::render_row( $entry, $row_num, $privacy_mode, $is_admin, $is_partner_user, $partners_enabled, $show_info_column );
		}

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render a single table row with per-row masking
	 *
	 * @param array  $entry            GF entry
	 * @param int    $row_num          Row number (1-indexed)
	 * @param string $privacy_mode     Privacy mode setting
	 * @param bool   $is_admin         Whether user is admin
	 * @param bool   $is_partner_user  Whether user is a partner
	 * @param bool   $partners_enabled Whether partners are enabled for this event
	 * @param bool   $show_info_column Whether Info column is shown
	 * @return string HTML row
	 */
	private static function render_row( array $entry, int $row_num, string $privacy_mode, bool $is_admin, bool $is_partner_user, bool $partners_enabled, bool $show_info_column ) : string {
		// Extract field values safely
		$first_name = self::get_field_value( $entry, 'first_name' );
		$last_name  = self::get_field_value( $entry, 'last_name' );
		$email      = self::get_field_value( $entry, 'email' );
		$bike       = self::get_bicycle_value( $entry );
		$pedals     = self::get_field_value( $entry, 'pedals' );
		$helmet     = self::get_field_value( $entry, 'helmet' );
		$entry_id   = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

		// Determine ownership by user ID (not coupon code)
		$is_owned = self::entry_owned_by_current_user( $entry );

		// Per-row masking decision
		// $row_can_view_full = admin OR (partners_enabled AND is_partner_user AND is_owned)
		$row_can_view_full = $is_admin || ( $partners_enabled && $is_partner_user && $is_owned );

		// privacy_mode=full always unmasked for everyone (existing behavior)
		if ( $privacy_mode === self::PRIVACY_FULL ) {
			$row_can_view_full = true;
		}

		$mask_data = ! $row_can_view_full;

		// Format participant name (apply masking if needed)
		$participant_name = self::format_participant_name( $first_name, $last_name, $mask_data );

		// Format email (apply masking if needed)
		$display_email = $mask_data ? self::mask_email( $email ) : $email;
		if ( empty( $display_email ) ) {
			$display_email = '—';
		}

		// Format bicycle info
		$display_bike = ! empty( $bike ) ? $bike : '—';

		// Format pedals
		$display_pedals = ! empty( $pedals ) ? $pedals : '—';

		// Format helmet
		$display_helmet = ! empty( $helmet ) ? $helmet : '—';

		// Format date (date_created is in UTC, convert to local)
		$date_created = isset( $entry['date_created'] ) ? $entry['date_created'] : '';
		$display_date = self::format_date( $date_created );

		// Get status from tcbf_state meta (hardened access)
		$status = self::get_entry_status( $entry_id );

		// Build row with data-label attributes for mobile
		$html = '<tr>';
		$html .= '<td class="tcbf-col-number" data-label="#">' . esc_html( $row_num ) . '</td>';
		$html .= '<td class="tcbf-col-participant" data-label="' . esc_attr__('Participant', 'tc-booking-flow-next') . '">' . esc_html( $participant_name ) . '</td>';
		$html .= '<td class="tcbf-col-email" data-label="' . esc_attr__('Email', 'tc-booking-flow-next') . '">' . esc_html( $display_email ) . '</td>';
		$html .= '<td class="tcbf-col-bicycle" data-label="' . esc_attr__('Bicycle + size', 'tc-booking-flow-next') . '">' . esc_html( $display_bike ) . '</td>';
		$html .= '<td class="tcbf-col-pedals" data-label="' . esc_attr__('Pedals', 'tc-booking-flow-next') . '">' . esc_html( $display_pedals ) . '</td>';
		$html .= '<td class="tcbf-col-helmet" data-label="' . esc_attr__('Helmet', 'tc-booking-flow-next') . '">' . esc_html( $display_helmet ) . '</td>';
		$html .= '<td class="tcbf-col-date" data-label="' . esc_attr__('Signed up on', 'tc-booking-flow-next') . '">' . esc_html( $display_date ) . '</td>';
		$html .= '<td class="tcbf-col-status" data-label="' . esc_attr__('Status', 'tc-booking-flow-next') . '"><span class="tcbf-status tcbf-status--' . esc_attr( sanitize_html_class( $status['class'] ) ) . '">' . esc_html( $status['label'] ) . '</span></td>';

		// Info status column (admin + partner only)
		if ( $show_info_column ) {
			// Admin sees all info; partner only sees info for their owned rows
			$show_info_badge = $is_admin || $row_can_view_full;

			if ( $show_info_badge ) {
				$info_status = self::get_notification_status( $entry_id );
				$html .= '<td class="tcbf-col-info" data-label="' . esc_attr__('Info', 'tc-booking-flow-next') . '"><span class="tcbf-info-status tcbf-info-status--' . esc_attr( sanitize_html_class( $info_status['class'] ) ) . '">' . esc_html( $info_status['label'] ) . '</span></td>';
			} else {
				// Partner viewing non-owned row - show "—" to avoid leaking operational timing
				$html .= '<td class="tcbf-col-info" data-label="' . esc_attr__('Info', 'tc-booking-flow-next') . '">—</td>';
			}
		}

		$html .= '</tr>';

		return $html;
	}

	/**
	 * Get field value from entry using field map
	 *
	 * @param array  $entry     GF entry
	 * @param string $field_key Key in field_map
	 * @return string Field value or empty string
	 */
	private static function get_field_value( array $entry, string $field_key ) : string {
		if ( ! isset( self::$field_map[ $field_key ] ) ) {
			return '';
		}

		$field_id = self::$field_map[ $field_key ];

		// Handle nested field IDs (e.g., "2.3" for name subfield)
		return isset( $entry[ $field_id ] ) ? trim( (string) $entry[ $field_id ] ) : '';
	}

	/**
	 * Get bicycle value with fallback chain
	 *
	 * Tries multiple fields in order because bike selection
	 * may be stored in different fields depending on the rental flow:
	 * - Field 146: derived summary text (preferred)
	 * - Fields 130/142/143/169: actual radio selections per bike type
	 *
	 * @param array $entry GF entry
	 * @return string Bicycle model + size, or empty string
	 */
	private static function get_bicycle_value( array $entry ) : string {
		foreach ( self::$bike_fields as $field_id ) {
			$value = isset( $entry[ $field_id ] ) ? trim( (string) $entry[ $field_id ] ) : '';
			if ( $value !== '' ) {
				return self::format_bicycle_value( $value );
			}
		}
		return '';
	}

	/**
	 * Format bicycle value for display
	 *
	 * Converts product_id_resource_id tokens (e.g., "47852_47865") into
	 * human-readable labels like "Product Name — Resource Name".
	 *
	 * @param string $raw Raw bicycle value from GF
	 * @return string Formatted display value
	 */
	private static function format_bicycle_value( string $raw ) : string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		// Check for product_id_resource_id pattern (e.g., "47852_47865")
		if ( preg_match( '/^(\d+)_(\d+)$/', $raw, $matches ) ) {
			$product_id  = (int) $matches[1];
			$resource_id = (int) $matches[2];

			$product_name  = self::get_product_name( $product_id );
			$resource_name = self::get_resource_name( $resource_id );

			// Build display string based on what we have
			if ( $product_name && $resource_name ) {
				return $product_name . ' — ' . $resource_name;
			} elseif ( $product_name ) {
				return $product_name;
			} elseif ( $resource_name ) {
				return $resource_name;
			}

			// Fallback: return raw token if we couldn't resolve either
			return $raw;
		}

		// Not a token pattern — return as-is (already human-readable)
		return $raw;
	}

	/**
	 * Get WooCommerce product name by ID
	 *
	 * @param int $product_id Product ID
	 * @return string Product name or empty string
	 */
	private static function get_product_name( int $product_id ) : string {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}

		return $product->get_name();
	}

	/**
	 * Get resource name by ID (post title or term name)
	 *
	 * @param int $resource_id Resource ID (typically a post or term)
	 * @return string Resource name or empty string
	 */
	private static function get_resource_name( int $resource_id ) : string {
		if ( $resource_id <= 0 ) {
			return '';
		}

		// Try as post first
		$title = get_the_title( $resource_id );
		if ( $title && $title !== '' ) {
			return $title;
		}

		// Fallback: try as term (some booking systems store resources as terms)
		$term = get_term( $resource_id );
		if ( $term && ! is_wp_error( $term ) && isset( $term->name ) ) {
			return $term->name;
		}

		return '';
	}

	/**
	 * Safe substr with mbstring fallback
	 *
	 * @param string $string Input string
	 * @param int    $start  Start position
	 * @param int    $length Length to extract
	 * @return string Substring
	 */
	private static function safe_substr( string $string, int $start, int $length ) : string {
		if ( function_exists('mb_substr') ) {
			return mb_substr( $string, $start, $length, 'UTF-8' );
		}
		return substr( $string, $start, $length );
	}

	/**
	 * Format participant name with optional masking
	 *
	 * Masking format: FirstName L.
	 *
	 * @param string $first_name First name
	 * @param string $last_name  Last name
	 * @param bool   $mask       Whether to mask
	 * @return string Formatted name
	 */
	private static function format_participant_name( string $first_name, string $last_name, bool $mask ) : string {
		$first_name = trim( $first_name );
		$last_name  = trim( $last_name );

		if ( empty( $first_name ) && empty( $last_name ) ) {
			return '—';
		}

		if ( $mask ) {
			// Masking: FirstName + first letter of last name + period
			$last_initial = '';
			if ( ! empty( $last_name ) ) {
				$last_initial = self::safe_substr( $last_name, 0, 1 ) . '.';
			}
			return $first_name . ( $last_initial ? ' ' . $last_initial : '' );
		}

		// Full name
		return $first_name . ( ! empty( $last_name ) ? ' ' . $last_name : '' );
	}

	/**
	 * Mask email address
	 *
	 * Format: lu***@gm***.com
	 *
	 * @param string $email Email address
	 * @return string Masked email
	 */
	private static function mask_email( string $email ) : string {
		$email = trim( $email );
		if ( empty( $email ) || strpos( $email, '@' ) === false ) {
			return '';
		}

		$parts = explode( '@', $email, 2 );
		$local = $parts[0];
		$domain = $parts[1];

		// Mask local part: show first 2 chars + ***
		$local_masked = self::safe_substr( $local, 0, 2 ) . '***';

		// Mask domain: show first 2 chars of domain name + *** + TLD
		$domain_parts = explode( '.', $domain );
		if ( count( $domain_parts ) >= 2 ) {
			$domain_name = $domain_parts[0];
			$tld = end( $domain_parts );
			$domain_masked = self::safe_substr( $domain_name, 0, 2 ) . '***.' . $tld;
		} else {
			$domain_masked = self::safe_substr( $domain, 0, 2 ) . '***';
		}

		return $local_masked . '@' . $domain_masked;
	}

	/**
	 * Format date for display
	 *
	 * @param string $date_created MySQL datetime string (UTC)
	 * @return string Formatted date
	 */
	private static function format_date( string $date_created ) : string {
		if ( empty( $date_created ) ) {
			return '—';
		}

		// Convert UTC to local time
		$timestamp = strtotime( $date_created . ' UTC' );
		if ( $timestamp === false ) {
			return '—';
		}

		// Format using WordPress date format
		return date_i18n( get_option( 'date_format', 'Y-m-d' ), $timestamp );
	}

	/**
	 * Get entry status from tcbf_state meta (hardened access)
	 *
	 * Maps all known entry states to human-readable labels and CSS classes.
	 *
	 * @param int $entry_id GF entry ID
	 * @return array ['label' => string, 'class' => string]
	 */
	private static function get_entry_status( int $entry_id ) : array {
		$state = '';

		// Hardened access: check if meta function exists
		if ( function_exists('gform_get_meta') && $entry_id > 0 ) {
			$state = (string) gform_get_meta( $entry_id, \TC_BF\Domain\Entry_State::META_STATE );
		}

		// Map state to display label and CSS class
		switch ( $state ) {
			case \TC_BF\Domain\Entry_State::STATE_PAID:
				return [ 'label' => __('Confirmed', 'tc-booking-flow-next'), 'class' => 'confirmed' ];

			case \TC_BF\Domain\Entry_State::STATE_IN_CART:
				return [ 'label' => __('In cart', 'tc-booking-flow-next'), 'class' => 'in-cart' ];

			case \TC_BF\Domain\Entry_State::STATE_CREATED:
				return [ 'label' => __('Created', 'tc-booking-flow-next'), 'class' => 'created' ];

			case \TC_BF\Domain\Entry_State::STATE_REMOVED:
				return [ 'label' => __('Removed', 'tc-booking-flow-next'), 'class' => 'removed' ];

			case \TC_BF\Domain\Entry_State::STATE_EXPIRED:
				return [ 'label' => __('Expired', 'tc-booking-flow-next'), 'class' => 'expired' ];

			case \TC_BF\Domain\Entry_State::STATE_PAYMENT_FAILED:
				return [ 'label' => __('Payment failed', 'tc-booking-flow-next'), 'class' => 'payment-failed' ];

			case \TC_BF\Domain\Entry_State::STATE_CANCELLED:
				return [ 'label' => __('Cancelled', 'tc-booking-flow-next'), 'class' => 'cancelled' ];

			case \TC_BF\Domain\Entry_State::STATE_REFUNDED:
				return [ 'label' => __('Refunded', 'tc-booking-flow-next'), 'class' => 'refunded' ];

			default:
				return [ 'label' => __('Unknown', 'tc-booking-flow-next'), 'class' => 'unknown' ];
		}
	}

	/**
	 * Render empty state message
	 *
	 * @return string HTML
	 */
	private static function render_empty_state() : string {
		return '<div class="tcbf-participants-list tcbf-participants-empty">'
			. '<p>' . esc_html__('No participants yet.', 'tc-booking-flow-next') . '</p>'
			. '</div>';
	}

	/**
	 * Return HTML comment for admin debugging (only visible to admins)
	 *
	 * @param string $message Debug message
	 * @return string HTML comment or empty
	 */
	private static function admin_debug_comment( string $message ) : string {
		if ( ! self::is_admin_user() ) {
			return '';
		}

		return '<!-- TCBF Participants Debug: ' . esc_html( $message ) . ' -->';
	}

	/* =========================================================
	 * Partner Authorization (User ID Ownership)
	 * ========================================================= */

	/**
	 * Check if partners are enabled for this event
	 *
	 * Wraps canonical EventMeta::event_partners_enabled().
	 *
	 * @param int $event_id Event post ID
	 * @return bool True if partners are enabled
	 */
	private static function partners_enabled_for_event( int $event_id ) : bool {
		if ( ! class_exists( '\\TC_BF\\Domain\\EventMeta' ) ) {
			return false;
		}

		return EventMeta::event_partners_enabled( $event_id );
	}

	/**
	 * Check if current user is a partner user
	 *
	 * Uses canonical partner detection via PartnerResolver.
	 * A user is a partner if they have a valid discount__code that
	 * resolves to an active partner context.
	 *
	 * @return bool True if current user is a partner
	 */
	private static function is_partner_user() : bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		if ( ! class_exists( '\\TC_BF\\Domain\\PartnerResolver' ) ) {
			return false;
		}

		$user_id = get_current_user_id();

		// Check if user has discount__code meta (canonical partner identifier)
		$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );

		if ( $code_raw === '' ) {
			return false;
		}

		// Build context to verify it's a valid/active partner
		$context = PartnerResolver::build_partner_context_from_code( $code_raw, $user_id );

		return ! empty( $context['active'] );
	}

	/**
	 * Check if entry is owned by current user (by user ID)
	 *
	 * Ownership is determined strictly by WP user ID, not coupon code.
	 *
	 * Primary: GF native created_by field
	 * Fallback: Hidden user ID field (if configured)
	 *
	 * @param array $entry GF entry
	 * @return bool True if entry is owned by current user
	 */
	private static function entry_owned_by_current_user( array $entry ) : bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$current_uid = get_current_user_id();

		// Primary: GF native created_by field
		$created_by = isset( $entry['created_by'] ) ? (int) $entry['created_by'] : 0;
		if ( $created_by > 0 ) {
			return $created_by === $current_uid;
		}

		// Fallback: Hidden user ID field (if configured in settings)
		$user_id_field = self::get_user_id_field_id();
		if ( $user_id_field > 0 ) {
			$field_value = isset( $entry[ (string) $user_id_field ] ) ? (int) $entry[ (string) $user_id_field ] : 0;
			if ( $field_value > 0 ) {
				return $field_value === $current_uid;
			}
		}

		return false;
	}

	/**
	 * Get the user ID field ID from settings
	 *
	 * Optional setting for fallback ownership detection when
	 * GF created_by is empty (e.g., guest submissions with user ID stored in hidden field).
	 *
	 * @return int Field ID or 0 if not configured
	 */
	private static function get_user_id_field_id() : int {
		return (int) get_option( self::OPT_USER_ID_FIELD, 0 );
	}

	/* =========================================================
	 * Notification Status Helpers
	 * ========================================================= */

	/**
	 * Get notification status from entry meta ledger
	 *
	 * Reads tcbf_notif_ledger meta and determines badge status.
	 *
	 * @param int $entry_id GF entry ID
	 * @return array ['label' => string, 'class' => string]
	 */
	private static function get_notification_status( int $entry_id ) : array {
		if ( $entry_id <= 0 || ! function_exists( 'gform_get_meta' ) ) {
			return [ 'label' => __('Not sent', 'tc-booking-flow-next'), 'class' => 'not-sent' ];
		}

		$ledger = gform_get_meta( $entry_id, self::META_NOTIF_LEDGER );

		// No ledger or empty ledger
		if ( empty( $ledger ) || ! is_array( $ledger ) ) {
			return [ 'label' => __('Not sent', 'tc-booking-flow-next'), 'class' => 'not-sent' ];
		}

		// Check if any notifications sent
		$has_sent = ! empty( $ledger['sent'] ) && is_array( $ledger['sent'] );

		// Check if any notifications failed
		$has_failed = ! empty( $ledger['failed'] ) && is_array( $ledger['failed'] );

		if ( $has_sent ) {
			// Show "Sent" with optional date
			$label = __('Sent', 'tc-booking-flow-next');
			if ( isset( $ledger['last_at'] ) && $ledger['last_at'] !== '' ) {
				$timestamp = strtotime( $ledger['last_at'] );
				if ( $timestamp !== false ) {
					$label .= ' ' . date_i18n( 'M j', $timestamp );
				}
			}
			return [ 'label' => $label, 'class' => 'sent' ];
		}

		if ( $has_failed ) {
			// Show "Failed" with optional date
			$label = __('Failed', 'tc-booking-flow-next');
			return [ 'label' => $label, 'class' => 'failed' ];
		}

		return [ 'label' => __('Not sent', 'tc-booking-flow-next'), 'class' => 'not-sent' ];
	}
}
