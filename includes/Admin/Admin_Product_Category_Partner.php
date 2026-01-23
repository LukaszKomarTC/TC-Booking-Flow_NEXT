<?php
namespace TC_BF\Admin;

use TC_BF\Domain\ProductPartnerConfig;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin Product Category Partner Settings
 *
 * Adds Partner program enable/disable toggle to WooCommerce product category edit screens.
 * Uses term meta to control whether the partner discount system is available per category.
 *
 * Supports 3-state configuration:
 * - Inherit (use global default)
 * - Enabled (explicitly enable)
 * - Disabled (explicitly disable)
 *
 * @since TCBF-14
 */
final class Admin_Product_Category_Partner {

	/**
	 * Initialize hooks
	 */
	public static function init() : void {
		// Add fields to product_cat taxonomy edit form
		add_action( 'product_cat_add_form_fields', [ __CLASS__, 'render_add_fields' ] );
		add_action( 'product_cat_edit_form_fields', [ __CLASS__, 'render_edit_fields' ], 10, 1 );

		// Save term meta
		add_action( 'created_product_cat', [ __CLASS__, 'save_fields' ], 10, 1 );
		add_action( 'edited_product_cat', [ __CLASS__, 'save_fields' ], 10, 1 );

		// Add column to category list
		add_filter( 'manage_edit-product_cat_columns', [ __CLASS__, 'add_column' ] );
		add_filter( 'manage_product_cat_custom_column', [ __CLASS__, 'render_column' ], 10, 3 );
	}

	/**
	 * Get the default label text
	 *
	 * @return string
	 */
	private static function get_default_label() : string {
		$global = ProductPartnerConfig::get_global_default();
		return $global
			? __( 'Inherit (currently: Enabled)', 'tc-booking-flow-next' )
			: __( 'Inherit (currently: Disabled)', 'tc-booking-flow-next' );
	}

	/**
	 * Render fields for "Add New Category" form
	 */
	public static function render_add_fields() : void {
		?>
		<div class="form-field">
			<label for="tcbf_partners_setting"><?php esc_html_e( 'Partner Program', 'tc-booking-flow-next' ); ?></label>
			<input type="hidden" name="tcbf_partners_enabled_set" value="1" />
			<select name="tcbf_partners_setting" id="tcbf_partners_setting" style="width: 100%;">
				<option value="inherit" selected><?php echo esc_html( self::get_default_label() ); ?></option>
				<option value="enabled"><?php esc_html_e( 'Enabled', 'tc-booking-flow-next' ); ?></option>
				<option value="disabled"><?php esc_html_e( 'Disabled', 'tc-booking-flow-next' ); ?></option>
			</select>
			<p class="description">
				<?php esc_html_e( 'Control whether partner discounts are available for booking products in this category.', 'tc-booking-flow-next' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render fields for "Edit Category" form
	 *
	 * @param \WP_Term $term Current term object
	 */
	public static function render_edit_fields( $term ) : void {
		$meta_value = get_term_meta( $term->term_id, ProductPartnerConfig::TERM_META_PARTNERS_ENABLED, true );

		// Determine current state
		if ( $meta_value === '' ) {
			$current = 'inherit';
		} elseif ( $meta_value === '1' ) {
			$current = 'enabled';
		} else {
			$current = 'disabled';
		}
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="tcbf_partners_setting"><?php esc_html_e( 'Partner Program', 'tc-booking-flow-next' ); ?></label>
			</th>
			<td>
				<input type="hidden" name="tcbf_partners_enabled_set" value="1" />
				<select name="tcbf_partners_setting" id="tcbf_partners_setting">
					<option value="inherit" <?php selected( $current, 'inherit' ); ?>><?php echo esc_html( self::get_default_label() ); ?></option>
					<option value="enabled" <?php selected( $current, 'enabled' ); ?>><?php esc_html_e( 'Enabled', 'tc-booking-flow-next' ); ?></option>
					<option value="disabled" <?php selected( $current, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'tc-booking-flow-next' ); ?></option>
				</select>
				<p class="description">
					<?php esc_html_e( 'Control whether partner discounts are available for booking products in this category. "Inherit" uses the global default setting.', 'tc-booking-flow-next' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Save term meta
	 *
	 * @param int $term_id Term ID
	 */
	public static function save_fields( int $term_id ) : void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Check if this is an AJAX request (handled separately)
		if ( wp_doing_ajax() ) {
			return;
		}

		// Only process if our hidden field is set (indicates form was submitted)
		if ( ! isset( $_POST['tcbf_partners_enabled_set'] ) ) {
			return;
		}

		$setting = isset( $_POST['tcbf_partners_setting'] ) ? sanitize_text_field( $_POST['tcbf_partners_setting'] ) : 'inherit';

		switch ( $setting ) {
			case 'enabled':
				ProductPartnerConfig::set_category_partners_enabled( $term_id, true );
				break;
			case 'disabled':
				ProductPartnerConfig::set_category_partners_enabled( $term_id, false );
				break;
			case 'inherit':
			default:
				// Clear meta to revert to global default
				ProductPartnerConfig::clear_category_partners_setting( $term_id );
				break;
		}
	}

	/**
	 * Add Partner column to category list
	 *
	 * @param array $columns Existing columns
	 * @return array Modified columns
	 */
	public static function add_column( array $columns ) : array {
		$columns['tcbf_partners'] = __( 'Partners', 'tc-booking-flow-next' );
		return $columns;
	}

	/**
	 * Render Partner column content
	 *
	 * @param string $content Column content
	 * @param string $column_name Column name
	 * @param int $term_id Term ID
	 * @return string Modified content
	 */
	public static function render_column( string $content, string $column_name, int $term_id ) : string {
		if ( $column_name !== 'tcbf_partners' ) {
			return $content;
		}

		$meta_value = get_term_meta( $term_id, ProductPartnerConfig::TERM_META_PARTNERS_ENABLED, true );
		$is_inherited = $meta_value === '';
		$enabled = ProductPartnerConfig::category_partners_enabled( $term_id );

		if ( $is_inherited ) {
			$icon = $enabled ? '<span style="color: #00a32a;">&#10003;</span>' : '<span style="color: #d63638;">&#10007;</span>';
			return $icon . ' <span style="color: #666;">' . esc_html__( '(default)', 'tc-booking-flow-next' ) . '</span>';
		}

		if ( $enabled ) {
			return '<span style="color: #00a32a;">&#10003;</span> ' . esc_html__( 'Enabled', 'tc-booking-flow-next' );
		} else {
			return '<span style="color: #d63638;">&#10007;</span> ' . esc_html__( 'Disabled', 'tc-booking-flow-next' );
		}
	}
}
