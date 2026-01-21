<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GF Notification Config
 *
 * Manages form/field mappings for notification templates.
 * Supports multiple forms via filterable configuration.
 *
 * Guardrails:
 * - No hardcoded form IDs (configurable via filter)
 * - Field mappings validated against actual form definition
 * - Fail-safe: missing required fields prevent sync
 *
 * @since 0.6.1
 */
final class GF_Notification_Config {

	/**
	 * Default field mappings (can be overridden per form)
	 */
	private static array $default_field_map = [
		// Participant fields
		'participant_email'       => 21,
		'participant_name'        => 2,      // Name field (use .3/.6 for subfields)
		'participant_name_first'  => '2.3',
		'participant_name_last'   => '2.6',
		'participant_phone'       => 123,

		// Notification control
		'notify_checkbox'         => 118,    // Main checkbox field
		'notify_checkbox_input'   => '118.1', // Specific input ID

		// Event fields
		'event_id'                => 20,
		'event_title'             => 1,
		'start_date'              => 131,
		'start_date_stamp'        => 132,
		'end_date'                => 133,
		'end_date_stamp'          => 134,

		// Pricing fields
		'total'                   => 76,
		'total_client'            => 168,
		'discount_amount'         => 164,

		// Partner fields
		'partner_email'           => 153,
		'partner_id'              => 166,
		'partner_discount_pct'    => 152,
		'partner_commission_pct'  => 161,
		'partner_commission_amt'  => 165,
		'discount_code'           => 154,

		// User fields
		'user_id'                 => 167,
		'user_role'               => 6,

		// Bike/rental fields
		'rental_selection'        => 106,
		'bike_model_size'         => 146,
		'bike_image_id'           => 147,
		'bike_image_src'          => 148,
		'pedals'                  => 60,
		'helmet'                  => 61,

		// Privacy/masked fields
		'email_masked'            => 150,
		'family_name_masked'      => 151,
	];

	/**
	 * Required fields for each notification type
	 */
	private static array $required_fields = [
		'participant_confirmation' => [
			'participant_email',
			'participant_name_first',
			'notify_checkbox',
			'event_title',
			'start_date',
			'total',
		],
		'partner_notification' => [
			'partner_email',
			'partner_id',
			'user_id',
			'discount_code',
			'event_title',
			'start_date',
		],
		'admin_notification' => [
			'event_title',
			'start_date',
			'participant_name_first',
		],
	];

	/**
	 * Get configured forms for notification sync
	 *
	 * Reads form ID from TCBF settings. Can be extended via filter for multiple forms.
	 *
	 * @return array<int, array> Form ID => config array
	 */
	public static function get_configured_forms(): array {
		// Get form ID from settings (falls back to 44 if not set)
		$form_id = self::get_settings_form_id();

		$forms = [
			$form_id => [
				'name'      => 'TCBF Booking Form',
				'field_map' => self::$default_field_map,
				'email'     => [
					'from_name'  => 'TOSSA CYCLING',
					'from_email' => 'info@tossacycling.com',
					'reply_to'   => 'info@tossacycling.com',
					'bcc'        => 'info@tossacycling.com',
				],
			],
		];

		/**
		 * Filter configured forms for notification sync
		 *
		 * Use this filter to:
		 * - Add additional forms for notification sync
		 * - Override field mappings for specific forms
		 * - Customize email settings per form
		 *
		 * @param array $forms Form configurations keyed by form ID
		 */
		return apply_filters( 'tcbf_notification_forms', $forms );
	}

	/**
	 * Get form ID from TCBF settings
	 *
	 * @return int Form ID
	 */
	private static function get_settings_form_id(): int {
		// Check if Settings class is available
		if ( class_exists( '\\TC_BF\\Admin\\Settings' ) ) {
			return \TC_BF\Admin\Settings::get_form_id();
		}

		// Fallback to option directly
		$form_id = (int) get_option( 'tc_bf_form_id', 44 );
		return $form_id > 0 ? $form_id : 44;
	}

	/**
	 * Get field map for a specific form
	 *
	 * @param int $form_id GF form ID
	 * @return array|null Field map or null if form not configured
	 */
	public static function get_field_map( int $form_id ): ?array {
		$forms = self::get_configured_forms();

		if ( ! isset( $forms[ $form_id ] ) ) {
			return null;
		}

		return $forms[ $form_id ]['field_map'] ?? self::$default_field_map;
	}

	/**
	 * Get email settings for a specific form
	 *
	 * @param int $form_id GF form ID
	 * @return array Email settings
	 */
	public static function get_email_settings( int $form_id ): array {
		$forms = self::get_configured_forms();

		$defaults = [
			'from_name'  => get_bloginfo( 'name' ),
			'from_email' => get_option( 'admin_email' ),
			'reply_to'   => get_option( 'admin_email' ),
			'bcc'        => '',
		];

		if ( ! isset( $forms[ $form_id ] ) ) {
			return $defaults;
		}

		return wp_parse_args( $forms[ $form_id ]['email'] ?? [], $defaults );
	}

	/**
	 * Get required fields for a notification type
	 *
	 * @param string $notification_type Notification type key
	 * @return array List of required field keys
	 */
	public static function get_required_fields( string $notification_type ): array {
		return self::$required_fields[ $notification_type ] ?? [];
	}

	/**
	 * Validate that all required fields exist in the form
	 *
	 * @param int    $form_id           GF form ID
	 * @param string $notification_type Notification type key
	 * @return array{valid: bool, missing: array, errors: array}
	 */
	public static function validate_form_fields( int $form_id, string $notification_type ): array {
		$result = [
			'valid'   => true,
			'missing' => [],
			'errors'  => [],
		];

		if ( ! class_exists( 'GFAPI' ) ) {
			$result['valid'] = false;
			$result['errors'][] = 'Gravity Forms not active';
			return $result;
		}

		$form = \GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			$result['valid'] = false;
			$result['errors'][] = "Form {$form_id} not found";
			return $result;
		}

		$field_map = self::get_field_map( $form_id );
		if ( ! $field_map ) {
			$result['valid'] = false;
			$result['errors'][] = "Form {$form_id} not configured for notifications";
			return $result;
		}

		$required = self::get_required_fields( $notification_type );
		$form_field_ids = self::extract_form_field_ids( $form );

		foreach ( $required as $field_key ) {
			if ( ! isset( $field_map[ $field_key ] ) ) {
				$result['missing'][] = $field_key;
				$result['valid'] = false;
				continue;
			}

			$field_id = $field_map[ $field_key ];
			$base_id = self::get_base_field_id( $field_id );

			if ( ! in_array( $base_id, $form_field_ids, true ) ) {
				$result['missing'][] = "{$field_key} (field {$field_id})";
				$result['valid'] = false;
			}
		}

		return $result;
	}

	/**
	 * Get checkbox field's actual stored value when checked
	 *
	 * @param int $form_id  GF form ID
	 * @param int $field_id Checkbox field ID
	 * @return string|null The value stored when checkbox is checked, or null
	 */
	public static function get_checkbox_value( int $form_id, int $field_id ): ?string {
		if ( ! class_exists( 'GFAPI' ) ) {
			return null;
		}

		$form = \GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return null;
		}

		foreach ( $form['fields'] as $field ) {
			if ( (int) $field->id === $field_id && $field->type === 'checkbox' ) {
				// Return the first choice value (for single-choice checkboxes)
				if ( ! empty( $field->choices[0]['value'] ) ) {
					return $field->choices[0]['value'];
				}
				// Fallback to choice text if value not set
				if ( ! empty( $field->choices[0]['text'] ) ) {
					return $field->choices[0]['text'];
				}
			}
		}

		return null;
	}

	/**
	 * Build conditional logic rule for checkbox field
	 *
	 * Uses the main checkbox field ID with "is" operator for exact match.
	 * Checkbox value should be simple (e.g., "1") for reliable matching.
	 *
	 * @param int $form_id GF form ID
	 * @return array|null Conditional logic rule or null if field not found
	 */
	public static function build_checkbox_condition( int $form_id ): ?array {
		$field_map = self::get_field_map( $form_id );
		if ( ! $field_map || ! isset( $field_map['notify_checkbox'] ) ) {
			return null;
		}

		// Use main checkbox field ID with "is" operator for exact match
		// Checkbox value is "1" (simple value, label remains multilingual)
		return [
			'fieldId'  => (string) $field_map['notify_checkbox'],
			'operator' => 'is',
			'value'    => '1',
		];
	}

	/**
	 * Build conditional logic rule for partner notification
	 *
	 * Partner notification should fire when:
	 * - User ID != Partner ID (not self-booking)
	 * - Discount code is not empty (partner attribution exists)
	 *
	 * @param int $form_id GF form ID
	 * @return array|null Conditional logic rules or null if fields not found
	 */
	public static function build_partner_condition( int $form_id ): ?array {
		$field_map = self::get_field_map( $form_id );
		if ( ! $field_map ) {
			return null;
		}

		$user_id_field = $field_map['user_id'] ?? null;
		$partner_id_field = $field_map['partner_id'] ?? null;
		$discount_code_field = $field_map['discount_code'] ?? null;

		if ( ! $user_id_field || ! $partner_id_field || ! $discount_code_field ) {
			return null;
		}

		return [
			[
				'fieldId'  => (string) $discount_code_field,
				'operator' => 'isnot',
				'value'    => '',
			],
			// Note: GF conditional logic cannot compare two fields directly
			// The "user != partner" check must be done via merge tag in partner email field
			// or via gform_notification filter. For now, we rely on discount_code presence.
		];
	}

	/**
	 * Extract all field IDs from a form
	 *
	 * @param array $form GF form array
	 * @return array<int> List of field IDs
	 */
	private static function extract_form_field_ids( array $form ): array {
		$ids = [];

		if ( empty( $form['fields'] ) ) {
			return $ids;
		}

		foreach ( $form['fields'] as $field ) {
			$ids[] = (int) $field->id;
		}

		return $ids;
	}

	/**
	 * Get base field ID from potentially compound ID (e.g., 2.3 -> 2)
	 *
	 * @param string|int $field_id Field ID (may include input suffix)
	 * @return int Base field ID
	 */
	private static function get_base_field_id( $field_id ): int {
		$str = (string) $field_id;
		if ( strpos( $str, '.' ) !== false ) {
			$parts = explode( '.', $str );
			return (int) $parts[0];
		}
		return (int) $field_id;
	}
}
