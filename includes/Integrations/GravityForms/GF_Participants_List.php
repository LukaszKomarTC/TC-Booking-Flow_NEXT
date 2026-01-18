<?php
/**
 * TCBF — Native Participants List (GFAPI, No GravityView)
 *
 * Renders a list of participants for an event using GFAPI directly.
 * Replaces GravityView dependency with clean, TCBF-native implementation.
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
	 * Field IDs (confirmed from gravityforms-export-2026-01-14.json)
	 */
	const FIELD_EVENT_UID = 145;           // Event unique ID
	const FIELD_NAME_FIRST = '2.3';        // Participant name (first name)
	const FIELD_NAME_LAST = '2.6';         // Participant family name (last name)
	const FIELD_EMAIL = 21;                // Email
	const FIELD_PHONE = 123;               // Phone number
	const FIELD_EMAIL_MASKED = 150;        // Participant email masked
	const FIELD_NAME_LAST_MASKED = 151;    // Participant family name masked

	/**
	 * Privacy mode setting (configurable)
	 *
	 * @var string 'public'|'privacy'
	 */
	private $privacy_mode = 'public';

	/**
	 * Constructor
	 */
	public function __construct() {
		// Privacy mode can be set via filter
		$this->privacy_mode = apply_filters( 'tcbf_participants_privacy_mode', 'public' );
	}

	/**
	 * Render participants list shortcode
	 *
	 * Usage: [tcbf_participants event_uid="ABC123"]
	 *
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function render_shortcode( $atts ) {
		// Parse attributes
		$atts = shortcode_atts(
			[
				'event_uid' => '',
				'privacy'   => $this->privacy_mode, // Can override via shortcode
			],
			$atts,
			'tcbf_participants'
		);

		// Validate event UID
		if ( empty( $atts['event_uid'] ) ) {
			return '<p class="tcbf-participants-error">' . esc_html__( 'Event UID is required.', 'tc-bf' ) . '</p>';
		}

		// Check if Gravity Forms is active
		if ( ! class_exists( 'GFAPI' ) ) {
			return '<p class="tcbf-participants-error">' . esc_html__( 'Gravity Forms is not active.', 'tc-bf' ) . '</p>';
		}

		// Query participants
		$entries = $this->get_participants( $atts['event_uid'] );

		// Handle no results
		if ( empty( $entries ) ) {
			return '<p class="tcbf-participants-empty">' . $this->translate( '[:es]No hay participantes registrados[:en]No participants registered[:]' ) . '</p>';
		}

		// Render table
		return $this->render_table( $entries, $atts['privacy'] );
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
					'key'   => (string) self::FIELD_EVENT_UID,
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
	 * @param string $privacy Privacy mode ('public' or 'privacy')
	 * @return string HTML table
	 */
	private function render_table( $entries, $privacy = 'public' ) {
		$is_privacy = ( $privacy === 'privacy' );

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
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) : ?>
						<tr>
							<td class="tcbf-col-name" data-label="<?php echo esc_attr( $this->translate( '[:es]Nombre[:en]Name[:]' ) ); ?>">
								<?php echo esc_html( $this->get_field_value( $entry, self::FIELD_NAME_FIRST ) ); ?>
							</td>
							<td class="tcbf-col-family-name" data-label="<?php echo esc_attr( $this->translate( '[:es]Apellidos[:en]Family name[:]' ) ); ?>">
								<?php
								if ( $is_privacy ) {
									// Privacy mode: use masked field if available
									$masked = $this->get_field_value( $entry, self::FIELD_NAME_LAST_MASKED );
									echo esc_html( $masked );
								} else {
									// Public mode: show full family name
									echo esc_html( $this->get_field_value( $entry, self::FIELD_NAME_LAST ) );
								}
								?>
							</td>
							<td class="tcbf-col-email" data-label="<?php echo esc_attr( $this->translate( '[:es]Email[:en]Email[:]' ) ); ?>">
								<?php
								if ( $is_privacy ) {
									// Privacy mode: use masked field if available
									$masked = $this->get_field_value( $entry, self::FIELD_EMAIL_MASKED );
									echo esc_html( $masked );
								} else {
									// Public mode: show full email (as mailto link)
									$email = $this->get_field_value( $entry, self::FIELD_EMAIL, '—' );
									if ( $email !== '—' && is_email( $email ) ) {
										echo '<a href="' . esc_attr( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
									} else {
										echo esc_html( $email );
									}
								}
								?>
							</td>
							<td class="tcbf-col-phone" data-label="<?php echo esc_attr( $this->translate( '[:es]Teléfono[:en]Phone[:]' ) ); ?>">
								<?php echo esc_html( $this->get_field_value( $entry, self::FIELD_PHONE ) ); ?>
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
