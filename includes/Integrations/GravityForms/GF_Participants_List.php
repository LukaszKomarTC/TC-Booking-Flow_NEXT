<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

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
		'event_uid'   => '145',  // Default, can be overridden via settings
		'bike_model'  => '146',  // Combined bike model and size
		'pedals'      => '60',
		'helmet'      => '61',
	];

	/**
	 * Option keys for plugin settings
	 */
	const OPT_PRIVACY_MODE   = 'tcbf_participants_privacy_mode';
	const OPT_EVENT_UID_FIELD = 'tcbf_participants_event_uid_field_id';

	/**
	 * Privacy mode constants
	 */
	const PRIVACY_PUBLIC_MASKED = 'public_masked';
	const PRIVACY_ADMIN_ONLY    = 'admin_only';
	const PRIVACY_FULL          = 'full';

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
	 * Event UID is computed internally:
	 * $event_uid = $event_id . '_' . get_post_meta($event_id, 'sc_event_date_time', true);
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

		// Check privacy mode
		$privacy_mode = self::get_privacy_mode();
		$is_admin = self::is_admin_user();

		// admin_only mode: hide list from public completely
		if ( $privacy_mode === self::PRIVACY_ADMIN_ONLY && ! $is_admin ) {
			return '';
		}

		// Query entries via GFAPI
		$entries = self::query_participants( $event_uid );

		if ( empty( $entries ) ) {
			return self::render_empty_state();
		}

		// Determine if we should mask data
		$should_mask = ( $privacy_mode === self::PRIVACY_PUBLIC_MASKED && ! $is_admin );

		// Render the table
		return self::render_table( $entries, $should_mask );
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

		// Build search criteria
		$search_criteria = [
			'field_filters' => [
				'mode' => 'all',
				// Filter by event UID
				[
					'key'      => (string) $uid_field_id,
					'value'    => $event_uid,
					'operator' => '=',
				],
				// Filter by paid state only
				[
					'key'   => \TC_BF\Domain\Entry_State::META_STATE,
					'value' => \TC_BF\Domain\Entry_State::STATE_PAID,
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
	 * @param array $entries   GF entries
	 * @param bool  $mask_data Whether to mask sensitive data
	 * @return string HTML table
	 */
	private static function render_table( array $entries, bool $mask_data ) : string {
		$html = '<div class="tcbf-participants-list">';
		$html .= '<table class="tcbf-participants-table">';

		// Table header
		$html .= '<thead><tr>';
		$html .= '<th class="tcbf-col-number">#</th>';
		$html .= '<th class="tcbf-col-participant">' . esc_html__('Participant', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-email">' . esc_html__('Email', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-bicycle">' . esc_html__('Bicycle + size', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-pedals">' . esc_html__('Pedals', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-helmet">' . esc_html__('Helmet', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-date">' . esc_html__('Signed up on', 'tc-booking-flow') . '</th>';
		$html .= '<th class="tcbf-col-status">' . esc_html__('Status', 'tc-booking-flow') . '</th>';
		$html .= '</tr></thead>';

		// Table body
		$html .= '<tbody>';
		$row_num = 0;

		foreach ( $entries as $entry ) {
			$row_num++;
			$html .= self::render_row( $entry, $row_num, $mask_data );
		}

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render a single table row
	 *
	 * @param array $entry     GF entry
	 * @param int   $row_num   Row number (1-indexed)
	 * @param bool  $mask_data Whether to mask sensitive data
	 * @return string HTML row
	 */
	private static function render_row( array $entry, int $row_num, bool $mask_data ) : string {
		// Extract field values safely
		$first_name = self::get_field_value( $entry, 'first_name' );
		$last_name  = self::get_field_value( $entry, 'last_name' );
		$email      = self::get_field_value( $entry, 'email' );
		$bike       = self::get_field_value( $entry, 'bike_model' );
		$pedals     = self::get_field_value( $entry, 'pedals' );
		$helmet     = self::get_field_value( $entry, 'helmet' );

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
		$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
		$status = self::get_entry_status( $entry_id );

		// Build row with data-label attributes for mobile
		$html = '<tr>';
		$html .= '<td class="tcbf-col-number" data-label="#">' . esc_html( $row_num ) . '</td>';
		$html .= '<td class="tcbf-col-participant" data-label="' . esc_attr__('Participant', 'tc-booking-flow') . '">' . esc_html( $participant_name ) . '</td>';
		$html .= '<td class="tcbf-col-email" data-label="' . esc_attr__('Email', 'tc-booking-flow') . '">' . esc_html( $display_email ) . '</td>';
		$html .= '<td class="tcbf-col-bicycle" data-label="' . esc_attr__('Bicycle + size', 'tc-booking-flow') . '">' . esc_html( $display_bike ) . '</td>';
		$html .= '<td class="tcbf-col-pedals" data-label="' . esc_attr__('Pedals', 'tc-booking-flow') . '">' . esc_html( $display_pedals ) . '</td>';
		$html .= '<td class="tcbf-col-helmet" data-label="' . esc_attr__('Helmet', 'tc-booking-flow') . '">' . esc_html( $display_helmet ) . '</td>';
		$html .= '<td class="tcbf-col-date" data-label="' . esc_attr__('Signed up on', 'tc-booking-flow') . '">' . esc_html( $display_date ) . '</td>';
		$html .= '<td class="tcbf-col-status" data-label="' . esc_attr__('Status', 'tc-booking-flow') . '"><span class="tcbf-status tcbf-status--' . esc_attr( sanitize_html_class( $status['class'] ) ) . '">' . esc_html( $status['label'] ) . '</span></td>';
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
				// Get first character (UTF-8 safe)
				$last_initial = mb_substr( $last_name, 0, 1, 'UTF-8' ) . '.';
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
		$local_masked = mb_substr( $local, 0, 2, 'UTF-8' ) . '***';

		// Mask domain: show first 2 chars of domain name + *** + TLD
		$domain_parts = explode( '.', $domain );
		if ( count( $domain_parts ) >= 2 ) {
			$domain_name = $domain_parts[0];
			$tld = end( $domain_parts );
			$domain_masked = mb_substr( $domain_name, 0, 2, 'UTF-8' ) . '***.' . $tld;
		} else {
			$domain_masked = mb_substr( $domain, 0, 2, 'UTF-8' ) . '***';
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
				return [ 'label' => __('Confirmed', 'tc-booking-flow'), 'class' => 'confirmed' ];
			case \TC_BF\Domain\Entry_State::STATE_IN_CART:
				return [ 'label' => __('In cart', 'tc-booking-flow'), 'class' => 'in-cart' ];
			case \TC_BF\Domain\Entry_State::STATE_CANCELLED:
				return [ 'label' => __('Cancelled', 'tc-booking-flow'), 'class' => 'cancelled' ];
			default:
				return [ 'label' => __('Unknown', 'tc-booking-flow'), 'class' => 'unknown' ];
		}
	}

	/**
	 * Render empty state message
	 *
	 * @return string HTML
	 */
	private static function render_empty_state() : string {
		return '<div class="tcbf-participants-list tcbf-participants-empty">'
			. '<p>' . esc_html__('No confirmed participants yet.', 'tc-booking-flow') . '</p>'
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
}
