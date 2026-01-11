<?php
namespace TC_BF\Admin;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner profile fields (legacy parity).
 *
 * Stores on user meta:
 * - usrdiscount    : partner commission percent (float)
 * - discount__code : partner coupon code (string)
 */
final class Partners {

	public const META_COMMISSION_PCT = 'usrdiscount';
	public const META_COUPON_CODE    = 'discount__code';

	public static function init() : void {
		add_action( 'show_user_profile', [ __CLASS__, 'render_fields' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'render_fields' ] );

		add_action( 'personal_options_update', [ __CLASS__, 'save_fields' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'save_fields' ] );
	}

	private static function can_manage() : bool {
		// Keep consistent with legacy: admins only.
		return current_user_can( 'manage_options' );
	}

	public static function render_fields( $user ) : void {
		if ( ! self::can_manage() ) return;

		$commission = get_user_meta( $user->ID, self::META_COMMISSION_PCT, true );
		$code       = get_user_meta( $user->ID, self::META_COUPON_CODE, true );

		$commission = ( $commission !== '' && is_numeric( $commission ) ) ? (float) $commission : '';
		$code       = is_string( $code ) ? $code : '';
		?>
		<h2><?php echo esc_html__( 'TC Partner Settings', 'tc-booking-flow' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="tc_bf_partner_commission"><?php echo esc_html__( 'Partner commission (%)', 'tc-booking-flow' ); ?></label></th>
				<td>
					<input
						type="number"
						step="0.01"
						min="0"
						max="100"
						id="tc_bf_partner_commission"
						name="tc_bf_partner_commission"
						value="<?php echo esc_attr( $commission ); ?>"
						class="regular-text"
					/>
					<p class="description">
						<?php echo esc_html__( 'Legacy key: usrdiscount. Used to compute partner commission on orders.', 'tc-booking-flow' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="tc_bf_partner_code"><?php echo esc_html__( 'Partner coupon code', 'tc-booking-flow' ); ?></label></th>
				<td>
					<input
						type="text"
						id="tc_bf_partner_code"
						name="tc_bf_partner_code"
						value="<?php echo esc_attr( $code ); ?>"
						class="regular-text"
						placeholder="HOTEL_XYZ"
					/>
					<p class="description">
						<?php echo esc_html__( 'Legacy key: discount__code. If set, this coupon can be auto-applied or used for reporting.', 'tc-booking-flow' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_fields( int $user_id ) : void {
		if ( ! self::can_manage() ) return;
		if ( ! current_user_can( 'edit_user', $user_id ) ) return;

		// Commission %
		if ( isset( $_POST['tc_bf_partner_commission'] ) ) {
			$raw = wp_unslash( $_POST['tc_bf_partner_commission'] );
			$raw = is_string( $raw ) ? trim( $raw ) : '';
			if ( $raw === '' ) {
				delete_user_meta( $user_id, self::META_COMMISSION_PCT );
			} else {
				$val = (float) $raw;
				if ( $val < 0 ) $val = 0;
				if ( $val > 100 ) $val = 100;
				update_user_meta( $user_id, self::META_COMMISSION_PCT, $val );
			}
		}

		// Coupon code
		if ( isset( $_POST['tc_bf_partner_code'] ) ) {
			$raw = wp_unslash( $_POST['tc_bf_partner_code'] );
			$raw = is_string( $raw ) ? trim( $raw ) : '';
			$raw = preg_replace( '/\s+/', '', $raw );
			$raw = strtoupper( $raw );

			if ( $raw === '' ) {
				delete_user_meta( $user_id, self::META_COUPON_CODE );
			} else {
				update_user_meta( $user_id, self::META_COUPON_CODE, sanitize_text_field( $raw ) );
			}
		}
	}
}
