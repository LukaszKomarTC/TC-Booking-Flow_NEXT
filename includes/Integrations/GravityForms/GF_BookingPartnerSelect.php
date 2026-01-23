<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Admin\Settings;
use TC_BF\Domain\PartnerResolver;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF_BookingPartnerSelect - Populate partner override select for booking forms
 *
 * Populates the partner_override_code field (CSS class: partner_override_code) with:
 * - Users with role 'hotel' (same as event form)
 * - Users with discount__code meta
 *
 * Also populates user_role field for conditional logic.
 *
 * Note: JS and CSS output is handled by unified GF_JS class.
 *
 * @since TCBF-14
 */
final class GF_BookingPartnerSelect {

	/**
	 * Initialize hooks
	 *
	 * Note: JS and CSS output is handled by unified GF_JS class.
	 * This class handles PHP-side form population only.
	 */
	public static function init() : void {
		add_filter( 'gform_pre_render',            [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_pre_validation',        [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_admin_pre_render',      [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_pre_submission_filter', [ __CLASS__, 'populate_partner_choices' ], 15, 1 );

		// Populate user_role hidden field for conditional logic
		add_filter( 'gform_field_value_user_role', [ __CLASS__, 'populate_user_role' ] );
	}

	/**
	 * Populate user_role field for conditional logic
	 *
	 * Returns comma-separated list of current user's roles for use
	 * in GF conditional logic (e.g., show partner select only to admin/hotel).
	 *
	 * @param mixed $value Default value
	 * @return string User roles as comma-separated string
	 */
	public static function populate_user_role( $value ) : string {
		if ( ! is_user_logged_in() ) {
			return 'guest';
		}

		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return 'guest';
		}

		$roles = (array) $user->roles;
		if ( empty( $roles ) ) {
			return 'subscriber';
		}

		// Return comma-separated roles for flexible conditional logic
		return implode( ',', $roles );
	}

	/**
	 * Populate partner select choices for booking form
	 *
	 * @param array $form GF form array
	 * @return array Modified form
	 */
	public static function populate_partner_choices( $form ) : array {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}

		$form_id = (int) $form['id'];
		$booking_form_id = Settings::get_booking_form_id();

		// Only process booking forms
		if ( $form_id !== $booking_form_id ) {
			return $form;
		}

		// Build partner choices
		$choices = self::build_partner_choices();

		// Find and populate partner_override_code field (select type)
		foreach ( $form['fields'] as &$field ) {
			$css_class = (string) ( $field->cssClass ?? '' );
			$input_name = (string) ( $field->inputName ?? '' );

			// Match by CSS class or inputName - business key is partner_override_code
			if ( strpos( $css_class, 'partner_override_code' ) !== false || $input_name === 'partner_override_code' ) {
				$field->choices = $choices;
			}
		}

		return $form;
	}

	/**
	 * Build partner choices array for select field
	 *
	 * @return array GF choices format
	 */
	private static function build_partner_choices() : array {
		$choices = [];

		// First option: direct booking (no partner)
		$choices[] = [
			'text'       => __( 'Direct booking (no partner)', 'tc-booking-flow-next' ),
			'value'      => '',
			'isSelected' => true,
		];

		// Get hotel users (same pattern as event form)
		$hotel_users = get_users([
			'role'    => 'hotel',
			'orderby' => 'user_nicename',
			'order'   => 'ASC',
		]);

		foreach ( $hotel_users as $user ) {
			$discount_code = (string) get_user_meta( $user->ID, 'discount__code', true );
			$discount_code = PartnerResolver::normalize_partner_code( $discount_code );

			if ( $discount_code === '' ) {
				continue;
			}

			$choices[] = [
				'text'  => $user->display_name . ' (' . $discount_code . ')',
				'value' => $discount_code,
			];
		}

		return $choices;
	}
}
