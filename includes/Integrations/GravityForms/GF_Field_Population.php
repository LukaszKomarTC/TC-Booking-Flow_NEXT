<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GF Field Population
 *
 * Populates derived/masked fields during form submission.
 * Migrated from legacy TC_snippets to ensure field values are available for notifications.
 *
 * Fields populated:
 * - 146: Bike model and size (derived from rental selection)
 * - 150: Masked email (privacy)
 * - 151: Masked family name (privacy)
 *
 * @since 0.6.2
 */
final class GF_Field_Population {

	/**
	 * Field mapping (can be filtered for different forms)
	 */
	private static array $field_map = [
		// Source fields
		'email'              => 21,
		'family_name'        => '2.6',
		'rental_type'        => 106,      // ROAD, MTB, eMTB, GRAVEL, or empty
		'bike_road'          => 130,
		'bike_mtb'           => 142,
		'bike_emtb'          => 143,
		'bike_gravel'        => 169,

		// Target fields (to populate)
		'bike_model_size'    => 146,
		'email_masked'       => 150,
		'family_name_masked' => 151,
	];

	/**
	 * Initialize field population hooks
	 */
	public static function init(): void {
		add_filter( 'gform_pre_submission', [ __CLASS__, 'populate_derived_fields' ], 10, 1 );
	}

	/**
	 * Populate derived fields before submission is saved
	 *
	 * @param array $form GF form array
	 */
	public static function populate_derived_fields( array $form ): void {
		$form_id = (int) ( $form['id'] ?? 0 );

		// Only process configured forms
		$configured_forms = GF_Notification_Config::get_configured_forms();
		if ( ! isset( $configured_forms[ $form_id ] ) ) {
			return;
		}

		$field_map = self::get_field_map( $form_id );

		// Populate masked email (field 150)
		self::populate_masked_email( $field_map );

		// Populate masked family name (field 151)
		self::populate_masked_family_name( $field_map );

		// Populate bike model and size (field 146)
		self::populate_bike_model_size( $field_map );

		Logger::log( 'gf_field_population.complete', [
			'form_id' => $form_id,
			'email_masked' => $_POST[ 'input_' . $field_map['email_masked'] ] ?? '',
			'family_name_masked' => $_POST[ 'input_' . $field_map['family_name_masked'] ] ?? '',
			'bike_model_size' => $_POST[ 'input_' . $field_map['bike_model_size'] ] ?? '',
		] );
	}

	/**
	 * Get field map for a form (filterable)
	 *
	 * @param int $form_id Form ID
	 * @return array Field mapping
	 */
	private static function get_field_map( int $form_id ): array {
		/**
		 * Filter field mapping for derived field population
		 *
		 * @param array $field_map Default field mapping
		 * @param int   $form_id   GF form ID
		 */
		return apply_filters( 'tcbf_field_population_map', self::$field_map, $form_id );
	}

	/**
	 * Populate masked email field
	 *
	 * @param array $field_map Field mapping
	 */
	private static function populate_masked_email( array $field_map ): void {
		$email_field = 'input_' . $field_map['email'];
		$target_field = 'input_' . $field_map['email_masked'];

		$email = sanitize_email( $_POST[ $email_field ] ?? '' );
		if ( empty( $email ) ) {
			return;
		}

		$masked = self::mask_email( $email );
		$_POST[ $target_field ] = $masked;
	}

	/**
	 * Populate masked family name field
	 *
	 * @param array $field_map Field mapping
	 */
	private static function populate_masked_family_name( array $field_map ): void {
		// Handle compound field ID (e.g., 2.6)
		$name_field_id = $field_map['family_name'];
		$input_key = 'input_' . str_replace( '.', '_', $name_field_id );
		$target_field = 'input_' . $field_map['family_name_masked'];

		$family_name = sanitize_text_field( $_POST[ $input_key ] ?? '' );
		if ( empty( $family_name ) ) {
			return;
		}

		$masked = self::mask_family_name( $family_name );
		$_POST[ $target_field ] = $masked;
	}

	/**
	 * Populate bike model and size field
	 *
	 * Format: "{Product Name} — Size: {Resource Name}"
	 * Example: "Canyon Spectral 125 — Size: M"
	 *
	 * @param array $field_map Field mapping
	 */
	private static function populate_bike_model_size( array $field_map ): void {
		$target_field = 'input_' . $field_map['bike_model_size'];

		// Get rental type selection
		$rental_type = sanitize_text_field( $_POST[ 'input_' . $field_map['rental_type'] ] ?? '' );
		if ( empty( $rental_type ) ) {
			// No rental selected - clear the field
			$_POST[ $target_field ] = '';
			return;
		}

		// Map rental type to bike field
		$bike_field_map = [
			'ROAD'   => $field_map['bike_road'],
			'MTB'    => $field_map['bike_mtb'],
			'eMTB'   => $field_map['bike_emtb'],
			'EMTB'   => $field_map['bike_emtb'],
			'GRAVEL' => $field_map['bike_gravel'],
		];

		$bike_field_id = $bike_field_map[ strtoupper( $rental_type ) ] ?? null;
		if ( ! $bike_field_id ) {
			return;
		}

		// Get selected bike value (format: {product_id}_{resource_id})
		$bike_value = sanitize_text_field( $_POST[ 'input_' . $bike_field_id ] ?? '' );
		if ( empty( $bike_value ) || strpos( $bike_value, 'not_avail' ) === 0 ) {
			return;
		}

		// Parse product and resource IDs
		$parts = explode( '_', $bike_value, 2 );
		if ( count( $parts ) !== 2 ) {
			return;
		}

		$product_id = (int) $parts[0];
		$resource_id = (int) $parts[1];

		if ( $product_id <= 0 || $resource_id <= 0 ) {
			return;
		}

		// Get product name
		$product_name = get_the_title( $product_id );
		if ( empty( $product_name ) ) {
			return;
		}

		// Get resource name (size)
		$resource_name = '';
		if ( class_exists( 'WC_Bookings_Resource' ) ) {
			try {
				$resource = new \WC_Bookings_Resource( $resource_id );
				$resource_name = $resource->get_title();
			} catch ( \Exception $e ) {
				// Fallback to post title
				$resource_name = get_the_title( $resource_id );
			}
		} else {
			$resource_name = get_the_title( $resource_id );
		}

		// Build the combined string
		$bike_model_size = $product_name;
		if ( ! empty( $resource_name ) ) {
			$bike_model_size .= ' — ' . __( 'Size', 'tc-booking-flow-next' ) . ': ' . $resource_name;
		}

		$_POST[ $target_field ] = $bike_model_size;
	}

	/**
	 * Mask email address
	 *
	 * Format: lu***@gm***.com
	 *
	 * @param string $email Email address
	 * @return string Masked email
	 */
	public static function mask_email( string $email ): string {
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
	 * Mask family name
	 *
	 * Format: First letter + period (e.g., "Smith" → "S.")
	 *
	 * @param string $name Family name
	 * @return string Masked name
	 */
	public static function mask_family_name( string $name ): string {
		$name = trim( $name );
		if ( empty( $name ) ) {
			return '';
		}

		return self::safe_substr( $name, 0, 1 ) . '.';
	}

	/**
	 * Multibyte-safe substring
	 *
	 * @param string $string Input string
	 * @param int    $start  Start position
	 * @param int    $length Length
	 * @return string Substring
	 */
	private static function safe_substr( string $string, int $start, int $length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $string, $start, $length, 'UTF-8' );
		}
		return substr( $string, $start, $length );
	}
}
