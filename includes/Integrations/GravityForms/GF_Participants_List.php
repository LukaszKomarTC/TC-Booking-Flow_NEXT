<?php
/**
 * TCBF — Native Participants List (GFAPI, No GravityView)
 *
 * Renders a list of participants for an event using GFAPI directly.
 * Replaces GravityView dependency with clean, TCBF-native implementation.
 *
 * Design principles:
 * - TCBF owns privacy rules (not GF forms)
 * - Plugin settings are the authority
 * - Field mapping centralized in one place
 * - Templates request data, never compute identifiers
 *
 * @package TC_BF
 * @since 1.0.0
 */

namespace TC_BF\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GF_Participants_List
 *
 * Provides [tcbf_participants] shortcode for rendering event participant lists.
 */
class GF_Participants_List {

	/**
	 * Gravity Forms form ID (main event booking form)
	 *
	 * @var int
	 */
	const FORM_ID = 48;

	/**
	 * Centralized GF field mapping (single source of truth)
	 *
	 * All GF field IDs live here. Rendering code never references raw IDs.
	 * Missing field → output "—", never fatal.
	 *
	 * @var array
	 */
	private static $field_map = [
		'event_uid'     => 145,        // Event unique ID
		'name'          => [
			'first'     => '2.3',      // Participant first name
			'last'      => '2.6',      // Participant family name
		],
		'email'         => 21,         // Email
		'phone'         => 123,        // Phone number
		'bike_road'     => 130,        // Road bike rental choice
		'bike_mtb'      => 142,        // MTB rental choice
		'bike_emtb'     => 143,        // E-MTB rental choice
		'bike_gravel'   => 169,        // Gravel bike rental choice
	];

	/**
	 * Privacy mode setting options
	 */
	const PRIVACY_PUBLIC = 'public';           // Full data visible to all
	const PRIVACY_PUBLIC_MASKED = 'public_masked'; // Masked for non-admins
	const PRIVACY_ADMIN_ONLY = 'admin_only';   // Hidden from public entirely

	/**
	 * Constructor
	 */
	public function __construct() {
		// No state needed - stateless rendering
	}

	/**
	 * Render participants list shortcode
	 *
	 * Usage: [tcbf_participants event_id="123"]
	 *
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function render_shortcode( $atts ) {
		// Parse attributes
		$atts = shortcode_atts(
			[
				'event_id' => 0,
			],
			$atts,
			'tcbf_participants'
		);

		// Determine event ID
		$event_id = (int) $atts['event_id'];
		if ( $event_id <= 0 ) {
			// Fallback to current post (if on sc_event single)
			if ( is_singular( 'sc_event' ) ) {
				$event_id = (int) get_the_ID();
			}
		}

		if ( $event_id <= 0 ) {
			return '<p class="tcbf-participants-error">' . esc_html__( 'Event ID is required.', 'tc-bf' ) . '</p>';
		}

		// Check if Gravity Forms is active
		if ( ! class_exists( 'GFAPI' ) ) {
			return '<p class="tcbf-participants-error">' . esc_html__( 'Gravity Forms is not active.', 'tc-bf' ) . '</p>';
		}

		// Get privacy mode (plugin settings are authority)
		$privacy_mode = $this->get_privacy_mode();

		// Admin-only mode: hide list completely from non-admins
		if ( $privacy_mode === self::PRIVACY_ADMIN_ONLY && ! $this->is_admin_user() ) {
			return ''; // Silent hide
		}

		// Compute Event UID (business logic belongs in PHP, not templates)
		$event_uid = $this->compute_event_uid( $event_id );
		if ( ! $event_uid ) {
			return '<p class="tcbf-participants-error">' . esc_html__( 'Event date not found.', 'tc-bf' ) . '</p>';
		}

		// Query participants
		$entries = $this->get_participants( $event_uid );

		// Handle no results
		if ( empty( $entries ) ) {
			return '<p class="tcbf-participants-empty">' . $this->translate( '[:es]No hay participantes registrados[:en]No participants registered[:]' ) . '</p>';
		}

		// Render table
		return $this->render_table( $entries, $privacy_mode );
	}

	/**
	 * Compute Event UID from event ID
	 *
	 * Format: {event_id}_{timestamp}
	 * This is TCBF's responsibility, not the template's.
	 *
	 * @param int $event_id Event post ID
	 * @return string|null Event UID or null if date missing
	 */
	private function compute_event_uid( $event_id ) {
		$date_time = get_post_meta( $event_id, 'sc_event_date_time', true );

		if ( ! $date_time ) {
			return null;
		}

		return $event_id . '_' . $date_time;
	}

	/**
	 * Get privacy mode (plugin settings are authority)
	 *
	 * Priority order:
	 * 1. Plugin setting (tcbf_participants_privacy_mode)
	 * 2. Filter override (for testing/staging)
	 * 3. Fallback to public_masked
	 *
	 * @return string Privacy mode constant
	 */
	private function get_privacy_mode() {
		// Read from plugin settings (default: public_masked per playbook)
		$setting = get_option( 'tcbf_participants_privacy_mode', self::PRIVACY_PUBLIC_MASKED );

		// Allow filter override (for testing/staging environments)
		$setting = apply_filters( 'tcbf_participants_privacy_mode', $setting );

		// Validate setting value
		$valid_modes = [ self::PRIVACY_PUBLIC, self::PRIVACY_PUBLIC_MASKED, self::PRIVACY_ADMIN_ONLY ];
		if ( ! in_array( $setting, $valid_modes, true ) ) {
			$setting = self::PRIVACY_PUBLIC_MASKED;
		}

		return $setting;
	}

	/**
	 * Check if current user is admin (full access)
	 *
	 * @return bool
	 */
	private function is_admin_user() {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		// Admin capability check
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// WooCommerce shop manager
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Query participants from GFAPI
	 *
	 * @param string $event_uid Event unique ID
	 * @return array Array of entry objects
	 */
	private function get_participants( $event_uid ) {
		// Build search criteria
		$search_criteria = [
			'status'        => 'active',
			'field_filters' => [
				[
					'key'   => (string) self::$field_map['event_uid'],
					'value' => $event_uid,
				],
			],
		];

		// Sorting (by entry ID ascending = chronological order)
		$sorting = [
			'key'        => 'id',
			'direction'  => 'ASC',
			'is_numeric' => true,
		];

		// Paging (get all entries, no limit)
		$paging = [
			'offset'    => 0,
			'page_size' => 999,
		];

		// Query GFAPI
		$entries = \GFAPI::get_entries( self::FORM_ID, $search_criteria, $sorting, $paging );

		// Handle errors
		if ( is_wp_error( $entries ) ) {
			return [];
		}

		return $entries;
	}

	/**
	 * Render participants table
	 *
	 * @param array  $entries Participant entries
	 * @param string $privacy Privacy mode
	 * @return string HTML table
	 */
	private function render_table( $entries, $privacy ) {
		$is_admin = $this->is_admin_user();
		$mask_data = ( $privacy === self::PRIVACY_PUBLIC_MASKED && ! $is_admin );

		ob_start();
		?>
		<div class="tcbf-participants-wrapper">
			<table class="tcbf-participants-table">
				<thead>
					<tr>
						<th class="tcbf-col-name"><?php echo esc_html( $this->translate( '[:es]Nombre[:en]Name[:]' ) ); ?></th>
						<th class="tcbf-col-family-name"><?php echo esc_html( $this->translate( '[:es]Apellidos[:en]Family name[:]' ) ); ?></th>
						<th class="tcbf-col-email"><?php echo esc_html( $this->translate( '[:es]Email[:en]Email[:]' ) ); ?></th>
						<th class="tcbf-col-phone"><?php echo esc_html( $this->translate( '[:es]Teléfono[:en]Phone[:]' ) ); ?></th>
						<th class="tcbf-col-status"><?php echo esc_html( $this->translate( '[:es]Estado[:en]Status[:]' ) ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<?php
						// Extract raw values using field map
						$first_name = $this->get_field_value( $entry, self::$field_map['name']['first'] );
						$last_name  = $this->get_field_value( $entry, self::$field_map['name']['last'] );
						$email      = $this->get_field_value( $entry, self::$field_map['email'] );
						$phone      = $this->get_field_value( $entry, self::$field_map['phone'] );
						$entry_id   = isset( $entry['id'] ) ? (int) $entry['id'] : 0;

						// Privacy masking (enforced in PHP, never in forms)
						if ( $mask_data ) {
							$display_first = $first_name;
							$display_last  = $this->mask_name( $last_name );
							$display_email = $this->mask_email( $email );
						} else {
							$display_first = $first_name;
							$display_last  = $last_name;
							$display_email = $email;
						}

						// Get participant status (hardened meta access)
						$status = $this->get_participant_status( $entry_id );
						?>
						<tr>
							<td class="tcbf-col-name" data-label="<?php echo esc_attr( $this->translate( '[:es]Nombre[:en]Name[:]' ) ); ?>">
								<?php echo esc_html( $display_first ); ?>
							</td>
							<td class="tcbf-col-family-name" data-label="<?php echo esc_attr( $this->translate( '[:es]Apellidos[:en]Family name[:]' ) ); ?>">
								<?php echo esc_html( $display_last ); ?>
							</td>
							<td class="tcbf-col-email" data-label="<?php echo esc_attr( $this->translate( '[:es]Email[:en]Email[:]' ) ); ?>">
								<?php
								if ( $mask_data ) {
									echo esc_html( $display_email );
								} else {
									// Full email as mailto link
									if ( $display_email !== '—' && is_email( $display_email ) ) {
										echo '<a href="' . esc_attr( 'mailto:' . $display_email ) . '">' . esc_html( $display_email ) . '</a>';
									} else {
										echo esc_html( $display_email );
									}
								}
								?>
							</td>
							<td class="tcbf-col-phone" data-label="<?php echo esc_attr( $this->translate( '[:es]Teléfono[:en]Phone[:]' ) ); ?>">
								<?php echo esc_html( $phone ); ?>
							</td>
							<td class="tcbf-col-status" data-label="<?php echo esc_attr( $this->translate( '[:es]Estado[:en]Status[:]' ) ); ?>">
								<?php echo $this->render_status_badge( $status ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get participant status (hardened meta access)
	 *
	 * @param int $entry_id GF entry ID
	 * @return string Status slug (paid|in_cart|cancelled|unknown)
	 */
	private function get_participant_status( $entry_id ) {
		if ( $entry_id <= 0 ) {
			return 'unknown';
		}

		// Hardened access: check function exists
		if ( ! function_exists( 'gform_get_meta' ) ) {
			return 'unknown';
		}

		$state = gform_get_meta( $entry_id, 'tcbf_state' );

		// Map state to display status
		switch ( $state ) {
			case 'paid':
				return 'paid';
			case 'in_cart':
				return 'in_cart';
			case 'cancelled':
				return 'cancelled';
			default:
				return 'unknown';
		}
	}

	/**
	 * Render status badge HTML
	 *
	 * @param string $status Status slug
	 * @return string HTML badge
	 */
	private function render_status_badge( $status ) {
		$labels = [
			'paid'      => $this->translate( '[:es]Confirmado[:en]Confirmed[:]' ),
			'in_cart'   => $this->translate( '[:es]En carrito[:en]In cart[:]' ),
			'cancelled' => $this->translate( '[:es]Cancelado[:en]Cancelled[:]' ),
			'unknown'   => $this->translate( '[:es]Desconocido[:en]Unknown[:]' ),
		];

		$label = isset( $labels[ $status ] ) ? $labels[ $status ] : $labels['unknown'];
		$class = 'tcbf-participant-status status-' . esc_attr( $status );

		return '<span class="' . $class . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * Mask name (privacy enforcement in PHP)
	 *
	 * Example: "González" → "G•••••••"
	 *
	 * @param string $name Full name
	 * @return string Masked name
	 */
	private function mask_name( $name ) {
		if ( $name === '—' || $name === '' ) {
			return '—';
		}

		$first_char = mb_substr( $name, 0, 1 );
		$length = mb_strlen( $name );

		if ( $length <= 1 ) {
			return $first_char;
		}

		return $first_char . str_repeat( '•', $length - 1 );
	}

	/**
	 * Mask email (privacy enforcement in PHP)
	 *
	 * Example: "john.doe@example.com" → "j•••@example.com"
	 *
	 * @param string $email Full email
	 * @return string Masked email
	 */
	private function mask_email( $email ) {
		if ( $email === '—' || $email === '' || ! is_email( $email ) ) {
			return '—';
		}

		$parts = explode( '@', $email );
		if ( count( $parts ) !== 2 ) {
			return '—';
		}

		$local = $parts[0];
		$domain = $parts[1];

		$first_char = mb_substr( $local, 0, 1 );
		$masked_local = $first_char . '•••';

		return $masked_local . '@' . $domain;
	}

	/**
	 * Get field value from entry with fallback
	 *
	 * @param array  $entry    Entry object
	 * @param mixed  $field_id Field ID (int or string for sub-fields)
	 * @param string $fallback Fallback value if empty
	 * @return string Field value or fallback
	 */
	private function get_field_value( $entry, $field_id, $fallback = '—' ) {
		$value = rgar( $entry, (string) $field_id );

		// Handle empty values
		if ( $value === null || $value === '' ) {
			return $fallback;
		}

		return trim( $value );
	}

	/**
	 * Translate text using TCBF translate function
	 *
	 * @param string $text Text to translate
	 * @return string Translated text
	 */
	private function translate( $text ) {
		// Use Woo::translate if available
		if ( class_exists( '\\TC_BF\\Integrations\\WooCommerce\\Woo' ) && method_exists( '\\TC_BF\\Integrations\\WooCommerce\\Woo', 'translate' ) ) {
			return \TC_BF\Integrations\WooCommerce\Woo::translate( $text );
		}

		// Fallback to direct function call
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			return tc_sc_event_tr( $text );
		}

		// Last resort: return as-is
		return $text;
	}
}
